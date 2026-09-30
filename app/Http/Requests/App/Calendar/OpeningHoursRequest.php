<?php

namespace App\Http\Requests\App\Calendar;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A shop's weekly opening hours (module 5.9). The route checks `calendar.manage`; a one-shop user may change their own
 * shop only (403), and "every shop" is refused for them. Times are checked by SaveOpeningHours. `clear` removes the week.
 */
class OpeningHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || ($this->route('branch') === $restricted && ! $this->boolean('everyShop'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clear' => ['sometimes', 'boolean'],
            'everyShop' => ['sometimes', 'boolean'],
            'days' => ['required_unless:clear,true', 'array', 'size:7'],
            'days.*.closed' => ['sometimes', 'boolean'],
            'days.*.opens' => ['nullable', 'string', 'max:5'],
            'days.*.closes' => ['nullable', 'string', 'max:5'],
        ];
    }

    /**
     * The week keyed by ISO weekday (1 = Monday), or null to clear it.
     *
     * @return array<int, array{closed?: mixed, opens?: mixed, closes?: mixed}>|null
     */
    public function week(): ?array
    {
        if ($this->boolean('clear')) {
            return null;
        }

        $week = [];
        foreach (array_values((array) $this->input('days', [])) as $i => $day) {
            $week[$i + 1] = is_array($day) ? $day : [];
        }

        return $week;
    }
}
