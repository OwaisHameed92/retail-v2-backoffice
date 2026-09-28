<?php

namespace App\Http\Requests\Api;

use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Enums\BusinessType as LeadBusinessType;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Enums\BusinessType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/public/trial-requests` (module 1.10, docs/specs/public-trial-api.md). Anyone may call it; the
 * honeypot and the per-IP limit run first (GuardPublicTrialRequests). Errors: 400 `request.invalid` with
 * `details.fields` = {field: [message]}.
 */
class StoreTrialRequest extends FormRequest
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
        return [
            'businessName' => ['required', 'string', 'max:160'],
            'contactName' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{7,}$/'],
            'town' => ['required', 'string', 'max:80'],
            'postcode' => ['required', 'string', 'max:10'],
            'shopsCount' => ['required', 'integer', 'min:1', 'max:'.Lead::MAX_SHOPS],
            'tillsCount' => ['required', 'integer', 'min:1', 'max:'.Lead::MAX_TILLS, 'gte:shopsCount'],
            'businessType' => ['required', 'string', Rule::enum(BusinessType::class)],
            'currentSystem' => ['nullable', 'string', 'max:160'],
            'marketingConsent' => ['sometimes', 'boolean'],
            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:100'],
            'utm.medium' => ['nullable', 'string', 'max:100'],
            'utm.campaign' => ['nullable', 'string', 'max:100'],
            'captchaToken' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'businessName.required' => 'Enter your business name.',
            'contactName.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address, like name@yourshop.co.uk.',
            'phone.required' => 'Enter a phone number so we can call you.',
            'phone.regex' => 'Enter a valid phone number, like 07700 900123.',
            'town.required' => 'Enter the town your shop is in.',
            'postcode.required' => 'Enter your shop’s postcode.',
            'shopsCount.*' => 'Enter between 1 and '.Lead::MAX_SHOPS.' shops.',
            'tillsCount.gte' => 'Enter at least one till for each shop.',
            'tillsCount.*' => 'Enter between 1 and '.Lead::MAX_TILLS.' tills.',
            'businessType.*' => 'Choose the kind of business you run.',
        ];
    }

    public function details(): LeadDetails
    {
        $type = BusinessType::from((string) $this->validated('businessType'));
        $leadType = LeadBusinessType::fromContract($type);
        $utm = array_filter([
            'utm_source' => $this->validated('utm.source'),
            'utm_medium' => $this->validated('utm.medium'),
            'utm_campaign' => $this->validated('utm.campaign'),
        ], fn (mixed $value) => is_string($value) && trim($value) !== '');

        return new LeadDetails(
            businessName: (string) $this->validated('businessName'),
            contactName: (string) $this->validated('contactName'),
            email: (string) $this->validated('email'),
            phone: (string) $this->validated('phone'),
            town: (string) $this->validated('town'),
            postcode: (string) $this->validated('postcode'),
            shopsCount: (int) $this->validated('shopsCount'),
            tillsCount: (int) $this->validated('tillsCount'),
            businessType: $leadType,
            currentSystem: is_string($system = $this->validated('currentSystem')) ? $system : null,
            // A lead has fewer kinds of business than the till; keep what they chose when it is not one of ours.
            message: $leadType === LeadBusinessType::Other && $type !== BusinessType::Other ? 'Business type: '.$type->label() : null,
            source: LeadSource::Website,
            consentMarketing: (bool) $this->validated('marketingConsent', false),
            utm: array_map(fn (string $value) => trim($value), $utm) ?: null,
            ip: $this->ip(),
        );
    }

    public function captchaToken(): ?string
    {
        $token = $this->validated('captchaToken');

        return is_string($token) ? $token : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException(
            'request.invalid',
            'Please check the form. '.$validator->errors()->first(),
            400,
            details: ['fields' => $validator->errors()->toArray()],
        );
    }
}
