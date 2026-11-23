<?php

namespace Tests\Feature;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('invalidDurations')]
    public function test_database_rejects_invalid_duration_outside_http_validation(int $seconds): void
    {
        $start = new CarbonImmutable('2026-11-02T07:00:00Z');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('bookings_valid_duration');

        Booking::factory()->create([
            'starts_at' => $start,
            'ends_at' => $start->addSeconds($seconds),
        ]);
    }

    /** @return array<string, array{int}> */
    public static function invalidDurations(): array
    {
        return [
            'one second below minimum' => [899],
            'one second above maximum' => [14401],
        ];
    }

    #[DataProvider('validDurations')]
    public function test_database_accepts_duration_boundaries(int $seconds): void
    {
        $start = new CarbonImmutable('2026-11-02T07:00:00Z');

        $booking = Booking::factory()->create([
            'starts_at' => $start,
            'ends_at' => $start->addSeconds($seconds),
        ]);

        $this->assertModelExists($booking);
        $this->assertDatabaseCount('bookings', 1);
    }

    /** @return array<string, array{int}> */
    public static function validDurations(): array
    {
        return ['fifteen minutes' => [900], 'four hours' => [14400]];
    }

    public function test_migration_round_trip_preserves_utc_moments_in_another_session_timezone(): void
    {
        $booking = Booking::factory()->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        $migration = require database_path('migrations/2026_11_20_101412_normalize_booking_timestamps_and_constrain_duration.php');
        DB::statement("SET LOCAL TIME ZONE 'Asia/Novosibirsk'");

        $migration->down();
        $legacy = DB::table('bookings')->find($booking->id);
        $this->assertSame('2026-11-02 07:00:00', $legacy->starts_at);
        $this->assertSame('2026-11-02 08:00:00', $legacy->ends_at);

        $migration->up();
        $restored = $booking->fresh();
        $sameMoment = DB::selectOne(
            'SELECT starts_at = CAST(? AS timestamptz) AS matches FROM bookings WHERE id = ?',
            ['2026-11-02T10:00:00+03:00', $booking->id],
        );

        $this->assertSame('2026-11-02T07:00:00.000000Z', $restored->starts_at->toISOString());
        $this->assertSame('2026-11-02T08:00:00.000000Z', $restored->ends_at->toISOString());
        $this->assertTrue($sameMoment->matches);
    }
}
