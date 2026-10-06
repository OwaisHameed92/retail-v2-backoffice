<?php

namespace App\Http\Requests\Admin;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new activate-by date for an unused key (module 1.11), as a UK calendar date. Route: `licences.manage`.
 */
class ActivateByRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['activate_by' => ['required', 'date_format:Y-m-d']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['activate_by.required' => 'Choose the date the key must be activated by.'];
    }

    public function until(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('activate_by'), Country::zone());
    }
}
