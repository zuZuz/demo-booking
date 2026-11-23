<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private const DATE_FORMAT_MESSAGE = 'Укажите дату и время в ISO 8601 с секундами и смещением Z или ±HH:MM.';

    private const GRID_MESSAGE = 'Время должно соответствовать шагу 15 минут без секунд и долей секунды.';

    private const HOURS_MESSAGE = 'Бронирование должно полностью укладываться в рабочее время офиса 09:00–20:00.';

    private CarbonInterface $startsAtUtc;

    private CarbonInterface $endsAtUtc;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $dateFormat = 'date_format:'.DateTimeInterface::ATOM.',Y-m-d\TH:i:s\Z';
        $dateRules = ['bail', 'required', 'string', $dateFormat];

        return [
            'room_id' => ['bail', 'required', 'integer:strict', 'min:1', 'exists:rooms,id'],
            'title' => ['bail', 'required', 'string', 'max:255', 'not_regex:/\x00/'],
            'starts_at' => $dateRules,
            'ends_at' => $dateRules,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.min' => 'Выберите существующую комнату.',
            'room_id.exists' => 'Выберите существующую комнату.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'title.not_regex' => 'Название встречи не должно содержать нулевой байт.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.string' => 'Время начала должно быть строкой.',
            'starts_at.date_format' => self::DATE_FORMAT_MESSAGE,
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.string' => 'Время окончания должно быть строкой.',
            'ends_at.date_format' => self::DATE_FORMAT_MESSAGE,
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $startsAt = $this->date('starts_at', '!'.DateTimeInterface::ATOM);
                $endsAt = $this->date('ends_at', '!'.DateTimeInterface::ATOM);

                foreach (['starts_at' => $startsAt, 'ends_at' => $endsAt] as $field => $date) {
                    if ($date->format('Y') === '0000' || abs($date->getOffset()) >= 24 * 60 * 60) {
                        $validator->errors()->add($field, self::DATE_FORMAT_MESSAGE);
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateInterval($startsAt, $endsAt, $validator);

                if ($validator->errors()->isEmpty()) {
                    $this->startsAtUtc = $startsAt->utc();
                    $this->endsAtUtc = $endsAt->utc();
                }
            },
        ];
    }

    public function startsAtUtc(): CarbonInterface
    {
        return $this->startsAtUtc;
    }

    public function endsAtUtc(): CarbonInterface
    {
        return $this->endsAtUtc;
    }

    private function validateInterval(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Validator $validator,
    ): void {
        $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($durationSeconds < 15 * 60 || $durationSeconds > 4 * 60 * 60) {
            $message = $durationSeconds <= 0
                ? 'Окончание бронирования должно быть позже начала.'
                : 'Длительность бронирования должна быть от 15 минут до 4 часов.';
            $validator->errors()->add('ends_at', $message);

            return;
        }

        $room = Room::with('office')->findOrFail($this->integer('room_id'));
        $start = $startsAt->copy()->setTimezone($room->office->timezone);
        $end = $endsAt->copy()->setTimezone($room->office->timezone);

        foreach (['starts_at' => $start, 'ends_at' => $end] as $field => $date) {
            if ((int) $date->format('i') % 15 !== 0 || $date->format('s') !== '00') {
                $validator->errors()->add($field, self::GRID_MESSAGE);
            }
        }

        $opening = $start->copy()->setTime(9, 0);
        $closing = $start->copy()->setTime(20, 0);

        if ($start < $opening || $start >= $closing) {
            $validator->errors()->add('starts_at', self::HOURS_MESSAGE);
        }

        if ($end > $closing || $end <= $opening || $end->format('Y-m-d') !== $start->format('Y-m-d')) {
            $validator->errors()->add('ends_at', self::HOURS_MESSAGE);
        }
    }
}
