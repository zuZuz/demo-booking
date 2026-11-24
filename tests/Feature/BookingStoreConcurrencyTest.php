<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class BookingStoreConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private string $serverDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverDirectory = sys_get_temp_dir().'/booking-concurrency-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->serverDirectory, 0700);
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', DB::selectOne('SELECT current_database() AS name')->name);
    }

    protected function tearDown(): void
    {
        if (isset($this->serverDirectory)) {
            File::deleteDirectory($this->serverDirectory);
        }

        parent::tearDown();
    }

    public function test_returns_409_for_a_concurrent_booking_of_the_same_interval(): void
    {
        $room = Room::factory()->create();
        $firstAuthor = User::factory()->create();
        $secondAuthor = User::factory()->create();

        [$firstResponse, $secondResponse] = $this->bookConcurrently($room, $firstAuthor, $secondAuthor);

        $this->assertSame(201, $firstResponse->status(), $firstResponse->json('message') ?? $firstResponse->body());
        $this->assertSame($firstAuthor->id, $firstResponse->json('user_id'));
        $this->assertSame(409, $secondResponse->status(), $secondResponse->json('message') ?? $secondResponse->body());
        $this->assertSame(['error' => 'Комната занята'], $secondResponse->json());
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $firstResponse->json('id'),
            'room_id' => $room->id,
            'user_id' => $firstAuthor->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        $this->assertOnlyBookingWasLogged($firstResponse->json('id'));
    }

    public function test_creates_waiting_booking_after_the_first_request_returns_500_and_rolls_back(): void
    {
        $room = Room::factory()->create();
        $firstAuthor = User::factory()->create();
        $secondAuthor = User::factory()->create();

        [$firstResponse, $secondResponse] = $this->bookConcurrently($room, $firstAuthor, $secondAuthor, failFirst: true);

        $this->assertSame(500, $firstResponse->status(), $firstResponse->json('message') ?? $firstResponse->body());
        $this->assertSame('Test failure after booking insertion.', $firstResponse->json('message'));
        $this->assertSame(201, $secondResponse->status(), $secondResponse->json('message') ?? $secondResponse->body());
        $this->assertSame($secondAuthor->id, $secondResponse->json('user_id'));

        $failedBookingId = (int) File::get($this->serverDirectory.'/first-booking-id');
        $this->assertDatabaseMissing('bookings', ['id' => $failedBookingId]);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', [
            'id' => $secondResponse->json('id'),
            'room_id' => $room->id,
            'user_id' => $secondAuthor->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        $this->assertOnlyBookingWasLogged($secondResponse->json('id'));
    }

    /**
     * Give each pooled request its own server so both handlers can run while one waits for PostgreSQL.
     *
     * @return array{Response, Response}
     */
    private function bookConcurrently(Room $room, User $firstAuthor, User $secondAuthor, bool $failFirst = false): array
    {
        $payload = [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ];
        $tokens = [
            'first' => $firstAuthor->createToken('first-concurrent-booking')->plainTextToken,
            'second' => $secondAuthor->createToken('second-concurrent-booking')->plainTextToken,
        ];
        $servers = [];
        $urls = [];

        try {
            foreach (array_keys($tokens) as $request) {
                [$server, $address] = $this->createServer($request, $failFirst);
                $servers[] = $server;
                $server->start();
                $this->assertTrue($server->waitUntil(
                    fn (string $type, string $output): bool => str_contains($output, '(http://'.$address.') started'),
                ), $server->getErrorOutput());

                $urls[$request] = 'http://'.$address.'/api/bookings';
            }

            $responses = Http::pool(function (Pool $pool) use ($urls, $payload, $tokens): void {
                foreach ($tokens as $request => $token) {
                    $pool->as($request)
                        ->withOptions(['proxy' => ''])
                        ->withToken($token)
                        ->acceptJson()
                        ->connectTimeout(2)
                        ->timeout(15)
                        ->post($urls[$request], $payload);
                }
            }, concurrency: 2);

            foreach ($responses as $response) {
                if ($response instanceof Throwable) {
                    throw $response;
                }
            }

            return [$responses['first'], $responses['second']];
        } finally {
            foreach ($servers as $server) {
                $server->stop(0);
            }
        }
    }

    /** @return array{Process, string} */
    private function createServer(string $request, bool $failFirst): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->assertIsString($address);

        $server = new Process(
            [PHP_BINARY, '-S', $address, '-t', public_path(), base_path('tests/Fixtures/booking-concurrency-server.php')],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_CONFIG_CACHE' => $this->serverDirectory.'/config.php',
                'PHP_CLI_SERVER_WORKERS' => false,
                'BOOKING_CONCURRENCY_DATABASE' => json_encode(config('database.connections.pgsql'), JSON_THROW_ON_ERROR),
                'BOOKING_CONCURRENCY_DIRECTORY' => $this->serverDirectory,
                'BOOKING_CONCURRENCY_FAIL_FIRST' => $failFirst ? '1' : '0',
                'BOOKING_CONCURRENCY_REQUEST' => $request,
            ],
            timeout: 5,
        );

        return [$server, $address];
    }

    private function assertOnlyBookingWasLogged(int $bookingId): void
    {
        $logs = File::lines($this->serverDirectory.'/bookings.log')
            ->filter(fn (string $line): bool => $line !== '')
            ->map(function (string $line): array {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                return ['message' => $record['message'], 'context' => $record['context']];
            })->all();

        $this->assertSame([
            ['message' => 'Booking created', 'context' => ['booking_id' => $bookingId]],
        ], $logs);
    }
}
