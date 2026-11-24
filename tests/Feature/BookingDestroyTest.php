<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_only_the_selected_past_booking(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-11-24T12:00:00Z'));
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create([
            'starts_at' => '2026-11-23 07:00:00',
            'ends_at' => '2026-11-23 08:00:00',
        ]);
        $otherBooking = Booking::factory()->for($author)->create();
        $token = $author->createToken('booking-cancellation')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();

        $this->assertModelMissing($booking);
        $this->assertModelExists($otherBooking);
    }

    public function test_returns_404_for_foreign_booking_even_with_its_user_id_in_the_request(): void
    {
        $ownBooking = Booking::factory()->create();
        $foreignBooking = Booking::factory()->create();
        Sanctum::actingAs($ownBooking->user);

        $data = [
            'id' => $ownBooking->id,
            'user_id' => $foreignBooking->user_id,
        ];
        $path = '/api/bookings/'.$foreignBooking->id.'?'.http_build_query($data);

        $this->deleteJson($path, $data)->assertNotFound();

        $this->assertModelExists($foreignBooking);
        $this->assertModelExists($ownBooking);
    }

    public function test_returns_401_without_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)->assertUnauthorized();

        $this->assertModelExists($booking);
    }

    #[DataProvider('invalidOrMissingIds')]
    public function test_returns_404_for_invalid_or_missing_booking_id(string $id): void
    {
        $booking = Booking::factory()->create(['id' => 1]);
        Sanctum::actingAs($booking->user);

        $this->deleteJson('/api/bookings/'.$id)->assertNotFound();

        $this->assertModelExists($booking);
    }

    /** @return array<string, array{string}> */
    public static function invalidOrMissingIds(): array
    {
        return [
            'non-numeric id' => ['1x'],
            'missing booking' => ['2'],
        ];
    }
}
