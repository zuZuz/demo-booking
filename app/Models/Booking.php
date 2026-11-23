<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

#[Fillable(['room_id', 'user_id', 'starts_at', 'ends_at', 'title'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected function asDateTime(mixed $value): CarbonInterface
    {
        if (is_string($value)) {
            $date = DateTimeImmutable::createFromFormat('!X-m-d H:i:sP', $value)
                ?: DateTimeImmutable::createFromFormat('!X-m-d H:i:s', $value);

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return Date::instance($date);
            }
        }

        return parent::asDateTime($value);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
