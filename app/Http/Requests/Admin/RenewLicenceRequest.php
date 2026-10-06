<?php

namespace App\Http\Requests\Admin;

use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\RenewalPeriod;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renew one licence or all of a company's licences: +1 month, +1 year or until a date (shop time zone).
 * Authorised by the `can:licences.manage` route middleware.
 */
class RenewLicenceRequest extends FormRequest
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
        $today = CarbonImmutable::now(Country::zone())->format('Y-m-d');

        return [
            'term' => ['required', Rule::enum(RenewalPeriod::class)],
            'until' => ['nullable', 'required_if:term,until', 'date_format:Y-m-d', 'after:'.$today],
            'notify' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'term.required' => 'Choose how long to renew for.',
            'until.required_if' => 'Choose the date the licence runs until.',
            'until.after' => 'Choose a date after today.',
            'until.date_format' => 'Enter a valid date.',
        ];
    }

    public function term(): RenewalTerm
    {
        $period = RenewalPeriod::from((string) $this->input('term'));

        return $period === RenewalPeriod::Until
            ? RenewalTerm::until(CarbonImmutable::parse((string) $this->input('until'), Country::zone()))
            : RenewalTerm::from($period);
    }

    public function notify(): bool
    {
        return $this->boolean('notify', true);
    }
}
