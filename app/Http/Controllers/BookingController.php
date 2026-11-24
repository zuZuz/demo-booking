<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): Booking|JsonResponse
    {
        $roomId = $request->validated('room_id');
        $startsAt = $request->startsAtUtc();
        $endsAt = $request->endsAtUtc();

        if (Booking::where('room_id', $roomId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists()) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        $booking = Booking::create([
            'room_id' => $roomId,
            'title' => $request->validated('title'),
            'user_id' => $request->user()->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return $booking;
    }

    public function destroy(Request $request, int $id): Response
    {
        $request->user()->bookings()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
