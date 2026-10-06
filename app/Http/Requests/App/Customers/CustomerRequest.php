<?php

namespace App\Http\Requests\App\Customers;

use App\Domain\Shared\Country\LocalText;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * Adding or editing a customer's details (module 4.4). Route: `company.can:customers.manage`. Customers are shared
 * by every shop, so a one-shop user may look but not change them (403, CompanyWideWriteRequest).
 */
class CustomerRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]*$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'dob' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'card_no' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]*$/'],
            'credit_limit' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'tier' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the customer\'s name.',
            'phone.regex' => 'Use digits, spaces, + and brackets only.',
            'email.email' => LocalText::domains('Enter a valid email address, e.g. name@example.co.uk.'),
            'dob.before' => 'The date of birth must be in the past.',
            'card_no.regex' => 'Use letters, digits and dashes only.',
            'credit_limit.regex' => 'Enter an amount in pounds, e.g. 50 or 49.99. Use 0 for no account credit.',
        ];
    }
}
