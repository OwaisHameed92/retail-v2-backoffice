<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A week of hours as the portal sends them (`{"monday": {"open": "08:00", "close": "20:00"}, "sunday": null, …}`, shop
 * time), checked and cleaned: HH:MM times, closing after opening, null for a day without slots. Used for delivery
 * hours (OS-9) and special hours on a closed day (OSA-13).
 */
final class WeekHours
{
    public const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @return array<string, array{open: string, close: string}|null>
     *
     * @throws ValidationException under `{field}.{day}.open|close`
     */
    public static function clean(mixed $week, string $field): array
    {
        $week = is_array($week) ? $week : [];
        $rules = [];

        foreach (OnlineStoreShop::WEEKDAYS as $day) {
            $rules["{$day}"] = ['nullable', 'array'];
            $rules["{$day}.open"] = ["required_with:{$day}", 'string', 'regex:'.self::TIME];
            $rules["{$day}.close"] = ["required_with:{$day}", 'string', 'regex:'.self::TIME];
        }

        $validator = Validator::make($week, $rules, ['regex' => 'Use a time like 08:30.', 'required_with' => 'Enter both times.']);

        if ($validator->fails()) {
            $errors = [];

            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors["{$field}.{$key}"] = $messages[0];
            }

            throw ValidationException::withMessages($errors);
        }

        $hours = [];

        foreach (OnlineStoreShop::WEEKDAYS as $day) {
            $value = $week[$day] ?? null;
            $hours[$day] = is_array($value) && isset($value['open'], $value['close']) ? self::pair((string) $value['open'], (string) $value['close'], "{$field}.{$day}") : null;
        }

        return $hours;
    }

    /**
     * @return array{open: string, close: string}
     *
     * @throws ValidationException
     */
    public static function pair(string $open, string $close, string $field): array
    {
        if (preg_match(self::TIME, $open) !== 1 || preg_match(self::TIME, $close) !== 1) {
            throw ValidationException::withMessages(["{$field}.open" => 'Use a time like 08:30.']);
        }

        if (strcmp($close, $open) <= 0) {
            throw ValidationException::withMessages(["{$field}.close" => 'Closing time must be after opening time.']);
        }

        return ['open' => $open, 'close' => $close];
    }
}
