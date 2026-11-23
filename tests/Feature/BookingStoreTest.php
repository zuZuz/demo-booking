<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.channels.bookings' => [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]]);
    }

    #[DataProvider('validIntervals')]
    public function test_creates_booking_and_preserves_utc_moments(
        string $timezone,
        string $start,
        string $end,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $room = $this->room($timezone);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->travelTo(new DateTimeImmutable('2026-11-20T12:00:00Z'));

        $response = $this->postJson('/api/bookings', [
            ...$this->payload($room), 'starts_at' => $start, 'ends_at' => $end,
        ]);

        $response->assertCreated()
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('title', 'Планёрка')
            ->assertJsonPath('starts_at', $expectedStart)
            ->assertJsonPath('ends_at', $expectedEnd);

        $this->assertDatabaseCount('bookings', 1);
        $booking = Booking::findOrFail($response->json('id'));
        $this->assertSame($expectedStart, $booking->starts_at->toISOString());
        $this->assertSame($expectedEnd, $booking->ends_at->toISOString());
        $this->assertSame($user->id, $booking->user_id);
    }

    /**
     * @return array<string, array{
     *     timezone: string,
     *     start: string,
     *     end: string,
     *     expectedStart: string,
     *     expectedEnd: string
     * }>
     */
    public static function validIntervals(): array
    {
        return [
            'Moscow' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T10:00:00+03:00',
                'end' => '2026-11-02T11:00:00+03:00',
                'expectedStart' => '2026-11-02T07:00:00.000000Z',
                'expectedEnd' => '2026-11-02T08:00:00.000000Z',
            ],
            'Novosibirsk' => [
                'timezone' => 'Asia/Novosibirsk',
                'start' => '2026-11-02T10:00:00+07:00',
                'end' => '2026-11-02T11:00:00+07:00',
                'expectedStart' => '2026-11-02T03:00:00.000000Z',
                'expectedEnd' => '2026-11-02T04:00:00.000000Z',
            ],
            'opening and minimum duration' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T09:00:00+03:00',
                'end' => '2026-11-02T09:15:00+03:00',
                'expectedStart' => '2026-11-02T06:00:00.000000Z',
                'expectedEnd' => '2026-11-02T06:15:00.000000Z',
            ],
            'four hours until closing' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T16:00:00+03:00',
                'end' => '2026-11-02T20:00:00+03:00',
                'expectedStart' => '2026-11-02T13:00:00.000000Z',
                'expectedEnd' => '2026-11-02T17:00:00.000000Z',
            ],
            'Novosibirsk opening UTC' => [
                'timezone' => 'Asia/Novosibirsk',
                'start' => '2026-11-02T02:00:00Z',
                'end' => '2026-11-02T02:15:00Z',
                'expectedStart' => '2026-11-02T02:00:00.000000Z',
                'expectedEnd' => '2026-11-02T02:15:00.000000Z',
            ],
            'negative offset' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T02:00:00-05:00',
                'end' => '2026-11-02T03:00:00-05:00',
                'expectedStart' => '2026-11-02T07:00:00.000000Z',
                'expectedEnd' => '2026-11-02T08:00:00.000000Z',
            ],
            'fractional hour offset' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T12:45:00+05:45',
                'end' => '2026-11-02T13:45:00+05:45',
                'expectedStart' => '2026-11-02T07:00:00.000000Z',
                'expectedEnd' => '2026-11-02T08:00:00.000000Z',
            ],
            'grid belongs to office' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T10:07:00+03:07',
                'end' => '2026-11-02T11:07:00+03:07',
                'expectedStart' => '2026-11-02T07:00:00.000000Z',
                'expectedEnd' => '2026-11-02T08:00:00.000000Z',
            ],
            'different offsets reverse clock labels' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-02T17:00:00+10:00',
                'end' => '2026-11-02T03:00:00-05:00',
                'expectedStart' => '2026-11-02T07:00:00.000000Z',
                'expectedEnd' => '2026-11-02T08:00:00.000000Z',
            ],
            'client previous day' => [
                'timezone' => 'Asia/Novosibirsk',
                'start' => '2026-11-01T14:00:00-12:00',
                'end' => '2026-11-01T15:00:00-12:00',
                'expectedStart' => '2026-11-02T02:00:00.000000Z',
                'expectedEnd' => '2026-11-02T03:00:00.000000Z',
            ],
            'client following day' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-03T06:45:00+14:00',
                'end' => '2026-11-03T07:00:00+14:00',
                'expectedStart' => '2026-11-02T16:45:00.000000Z',
                'expectedEnd' => '2026-11-02T17:00:00.000000Z',
            ],
            'past date' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2020-11-02T10:00:00+03:00',
                'end' => '2020-11-02T11:00:00+03:00',
                'expectedStart' => '2020-11-02T07:00:00.000000Z',
                'expectedEnd' => '2020-11-02T08:00:00.000000Z',
            ],
            'weekend' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2026-11-21T10:00:00+03:00',
                'end' => '2026-11-21T11:00:00+03:00',
                'expectedStart' => '2026-11-21T07:00:00.000000Z',
                'expectedEnd' => '2026-11-21T08:00:00.000000Z',
            ],
            'far future' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2099-11-02T10:00:00+03:00',
                'end' => '2099-11-02T11:00:00+03:00',
                'expectedStart' => '2099-11-02T07:00:00.000000Z',
                'expectedEnd' => '2099-11-02T08:00:00.000000Z',
            ],
            'leap day' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2028-02-29T10:00:00+03:00',
                'end' => '2028-02-29T11:00:00+03:00',
                'expectedStart' => '2028-02-29T07:00:00.000000Z',
                'expectedEnd' => '2028-02-29T08:00:00.000000Z',
            ],
            'year boundary and holiday' => [
                'timezone' => 'Europe/Moscow',
                'start' => '2027-01-01T10:00:00+03:00',
                'end' => '2027-01-01T11:00:00+03:00',
                'expectedStart' => '2027-01-01T07:00:00.000000Z',
                'expectedEnd' => '2027-01-01T08:00:00.000000Z',
            ],
            'offset crosses into UTC year 10000' => [
                'timezone' => 'Europe/Moscow',
                'start' => '9999-12-31T23:00:00-12:00',
                'end' => '9999-12-31T23:15:00-12:00',
                'expectedStart' => '+010000-01-01T11:00:00.000000Z',
                'expectedEnd' => '+010000-01-01T11:15:00.000000Z',
            ],
        ];
    }

    public function test_returns_422_for_empty_payload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])->assertUnprocessable()->assertInvalid([
            'room_id' => 'Укажите комнату.',
            'title' => 'Укажите название встречи.',
            'starts_at' => 'Укажите время начала.',
            'ends_at' => 'Укажите время окончания.',
        ]);

        $this->assertDatabaseEmpty('bookings');
        $this->assertSame([], $this->bookingLogs());
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_returns_422_for_invalid_payload(array $overrides, string $field, string $message): void
    {
        $payload = [...$this->payload($this->room()), ...$overrides];
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $payload)->assertUnprocessable()->assertInvalid([$field => $message]);

        $this->assertDatabaseEmpty('bookings');
        $this->assertSame([], $this->bookingLogs());
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'zero room' => [['room_id' => 0], 'room_id', 'Выберите существующую комнату.'],
            'unknown room' => [['room_id' => PHP_INT_MAX], 'room_id', 'Выберите существующую комнату.'],
            'boolean room' => [['room_id' => true], 'room_id', 'Идентификатор комнаты должен быть целым числом.'],
            'SQL in room' => [['room_id' => '0 OR 1=1'], 'room_id', 'Идентификатор комнаты должен быть целым числом.'],
            'array title' => [['title' => ['meeting']], 'title', 'Название встречи должно быть строкой.'],
            'long Unicode title' => [['title' => str_repeat('Я', 256)], 'title', 'Название встречи не должно превышать 255 символов.'],
            'null byte title' => [['title' => "meeting\0title"], 'title', 'Название встречи не должно содержать нулевой байт.'],
            'number start' => [['starts_at' => 1793602800], 'starts_at', 'Время начала должно быть строкой.'],
            'array end' => [['ends_at' => ['2026-11-02']], 'ends_at', 'Время окончания должно быть строкой.'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_returns_422_for_invalid_date_input(string $field, string $value): void
    {
        $payload = [...$this->payload($this->room()), $field => $value];
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $payload)->assertUnprocessable()
            ->assertInvalid([$field => 'Укажите дату и время в ISO 8601 с секундами и смещением Z или ±HH:MM.']);

        $this->assertDatabaseEmpty('bookings');
        $this->assertSame([], $this->bookingLogs());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidDates(): array
    {
        return [
            'missing end offset' => ['ends_at', '2026-11-02T11:00:00'],
            'hour-only offset' => ['starts_at', '2026-11-02T10:00:00+03'],
            'fractional seconds' => ['starts_at', '2026-11-02T10:00:00.0+03:00'],
            'impossible day' => ['starts_at', '2026-02-30T10:00:00+03:00'],
            'zero year' => ['starts_at', '0000-11-02T10:00:00+03:00'],
            'invalid offset hour' => ['ends_at', '2026-11-02T10:00:00+24:00'],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function test_returns_422_for_invalid_interval(
        string $start,
        string $end,
        string $field,
        string $message,
        string $timezone = 'Europe/Moscow',
    ): void {
        $payload = [...$this->payload($this->room($timezone)), 'starts_at' => $start, 'ends_at' => $end];
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $payload)->assertUnprocessable()->assertInvalid([$field => $message]);

        $this->assertDatabaseEmpty('bookings');
        $this->assertSame([], $this->bookingLogs());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4?: string}> */
    public static function invalidIntervals(): array
    {
        $duration = 'Длительность бронирования должна быть от 15 минут до 4 часов.';
        $order = 'Окончание бронирования должно быть позже начала.';
        $grid = 'Время должно соответствовать шагу 15 минут без секунд и долей секунды.';
        $hours = 'Бронирование должно полностью укладываться в рабочее время офиса 09:00–20:00.';

        return [
            'negative duration' => ['2026-11-02T10:00:00+03:00', '2026-11-02T09:00:00+03:00', 'ends_at', $order],
            'zero duration' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at', $order],
            'same instant different offsets' => ['2026-11-02T10:00:00+03:00', '2026-11-02T07:00:00Z', 'ends_at', $order],
            'clock labels hide negative duration' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+07:00', 'ends_at', $order],
            'five minutes' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:05:00+03:00', 'ends_at', $duration],
            'second below minimum' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:14:59+03:00', 'ends_at', $duration],
            'second above minimum violates grid' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:15:01+03:00', 'ends_at', $grid],
            'second below maximum violates grid' => ['2026-11-02T10:00:00+03:00', '2026-11-02T13:59:59+03:00', 'ends_at', $grid],
            'second above maximum' => ['2026-11-02T10:00:00+03:00', '2026-11-02T14:00:01+03:00', 'ends_at', $duration],
            'four hours fifteen' => ['2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00', 'ends_at', $duration],
            'next day same clock' => ['2026-11-02T10:00:00+03:00', '2026-11-03T10:00:00+03:00', 'ends_at', $duration],
            'both ends off grid' => ['2026-11-02T10:07:00+03:00', '2026-11-02T10:22:00+03:00', 'starts_at', $grid],
            'end off grid' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:16:00+03:00', 'ends_at', $grid],
            'seconds on both ends' => ['2026-11-02T10:00:01+03:00', '2026-11-02T10:15:01+03:00', 'starts_at', $grid],
            'before opening' => ['2026-11-02T08:45:00+03:00', '2026-11-02T09:00:00+03:00', 'starts_at', $hours],
            'after closing' => ['2026-11-02T19:45:00+03:00', '2026-11-02T20:15:00+03:00', 'ends_at', $hours],
            'starting at closing' => ['2026-11-02T20:00:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at', $hours],
            'crossing midnight' => ['2026-11-02T23:45:00+03:00', '2026-11-03T00:00:00+03:00', 'ends_at', $hours],
            'UTC clock valid but office closed' => ['2026-11-02T18:00:00Z', '2026-11-02T19:00:00Z', 'starts_at', $hours],
            'Novosibirsk before opening' => ['2026-11-02T01:45:00Z', '2026-11-02T02:00:00Z', 'starts_at', $hours, 'Asia/Novosibirsk'],
            'Novosibirsk after closing' => ['2026-11-02T12:45:00Z', '2026-11-02T13:15:00Z', 'ends_at', $hours, 'Asia/Novosibirsk'],
        ];
    }

    #[DataProvider('overlappingIntervals')]
    public function test_returns_409_for_overlap_and_preserves_existing_booking(string $start, string $end): void
    {
        $room = $this->room();
        $existing = Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 08:00:00',
        ]);
        $original = $existing->fresh()->getAttributes();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            ...$this->payload($room), 'starts_at' => $start, 'ends_at' => $end,
        ])->assertConflict()->assertExactJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertSame([], $this->bookingLogs());
    }

    /** @return array<string, array{string, string}> */
    public static function overlappingIntervals(): array
    {
        return [
            'contained' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00'],
            'enclosing' => ['2026-11-02T09:45:00+03:00', '2026-11-02T11:15:00+03:00'],
            'left overlap' => ['2026-11-02T09:45:00+03:00', '2026-11-02T10:15:00+03:00'],
            'right overlap' => ['2026-11-02T10:45:00+03:00', '2026-11-02T11:15:00+03:00'],
        ];
    }

    #[DataProvider('nonOverlappingIntervals')]
    public function test_creates_non_overlapping_booking(string $start, string $end): void
    {
        $room = $this->room();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            ...$this->payload($room), 'starts_at' => $start, 'ends_at' => $end,
        ])->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertCount(1, $this->bookingLogs());
    }

    /** @return array<string, array{string, string}> */
    public static function nonOverlappingIntervals(): array
    {
        return [
            'touching before' => ['2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00'],
            'touching after' => ['2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00'],
            'separate interval' => ['2026-11-02T12:00:00+03:00', '2026-11-02T13:00:00+03:00'],
            'another day' => ['2026-11-03T10:00:00+03:00', '2026-11-03T11:00:00+03:00'],
        ];
    }

    public function test_allows_same_user_to_book_different_rooms_at_same_time(): void
    {
        $room = $this->room();
        $user = User::factory()->create();
        Booking::factory()->for($user)->create(['starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 08:00:00']);
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
        $this->assertCount(1, $this->bookingLogs());
    }

    public function test_rejects_sequential_duplicate_in_another_offset(): void
    {
        $room = $this->room();
        Sanctum::actingAs(User::factory()->create());
        $first = $this->postJson('/api/bookings', $this->payload($room));
        $first->assertCreated();

        $this->postJson('/api/bookings', [
            ...$this->payload($room), 'starts_at' => '2026-11-02T07:00:00Z', 'ends_at' => '2026-11-02T08:00:00Z',
        ])->assertConflict()->assertExactJson(['error' => 'Комната занята']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertModelExists(Booking::findOrFail($first->json('id')));
        $this->assertCount(1, $this->bookingLogs());
    }

    public function test_ignores_foreign_author_and_other_untrusted_attributes(): void
    {
        $room = $this->room();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->travelTo(new DateTimeImmutable('2026-11-20T12:00:00Z'));
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', [
            ...$this->payload($room),
            'user_id' => $otherUser->id,
            'id' => 999999,
            'office_id' => 999999,
            'created_at' => '2000-01-01T00:00:00Z',
            'updated_at' => '2000-01-01T00:00:00Z',
        ]);

        $response->assertCreated()->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('created_at', '2026-11-20T12:00:00.000000Z')
            ->assertJsonPath('updated_at', '2026-11-20T12:00:00.000000Z');
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseHas('bookings', ['id' => $response->json('id'), 'room_id' => $room->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('bookings', ['id' => 999999]);
    }

    #[DataProvider('validTitles')]
    public function test_preserves_valid_title(string $title): void
    {
        $room = $this->room();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/bookings', [...$this->payload($room), 'title' => $title]);

        $response->assertCreated()->assertJsonPath('title', $title);
        $this->assertDatabaseHas('bookings', ['id' => $response->json('id'), 'title' => $title]);
    }

    /** @return array<string, array{string}> */
    public static function validTitles(): array
    {
        return ['one character' => ['Я'], '255 Unicode characters' => [str_repeat('Я', 255)], 'SQL is literal text' => ["O'Reilly'); DROP TABLE bookings; --"]];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('unauthenticatedHeaders')]
    public function test_returns_401_without_valid_authentication(array $headers): void
    {
        $payload = $this->payload($this->room());

        $this->postJson('/api/bookings', $payload, $headers)->assertUnauthorized();

        $this->assertDatabaseEmpty('bookings');
        $this->assertSame([], $this->bookingLogs());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function unauthenticatedHeaders(): array
    {
        return ['missing token' => [[]], 'invalid token' => [['Authorization' => 'Bearer invalid-token']]];
    }

    public function test_creates_booking_with_real_sanctum_token(): void
    {
        $room = $this->room();
        $user = User::factory()->create();
        $token = $user->createToken('booking-test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/bookings', $this->payload($room));

        $response->assertCreated()->assertJsonPath('user_id', $user->id);
        $this->assertDatabaseHas('bookings', ['id' => $response->json('id'), 'user_id' => $user->id]);
    }

    public function test_logs_booking_id_when_booking_is_created(): void
    {
        $room = $this->room();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/bookings', $this->payload($room));
        $response->assertCreated();

        $records = $this->bookingLogs();
        $this->assertCount(1, $records);
        $this->assertSame('Booking created', $records[0]->message);
        $this->assertSame(['booking_id' => $response->json('id')], $records[0]->context);
    }

    private function room(string $timezone = 'Europe/Moscow'): Room
    {
        $office = $timezone === 'Asia/Novosibirsk' ? Office::factory()->novosibirsk() : Office::factory();

        return Room::factory()->for($office)->create();
    }

    /** @return array{room_id: int, title: string, starts_at: string, ends_at: string} */
    private function payload(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ];
    }

    /** @return list<LogRecord> */
    private function bookingLogs(): array
    {
        return Log::channel('bookings')->getLogger()->getHandlers()[0]->getRecords();
    }
}
