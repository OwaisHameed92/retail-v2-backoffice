<?php

namespace App\Http\Requests\App\Salon;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Turn on (or renew) a stylist's calendar feed: the shop and the stylist's till user id. The shop must be an open
 * shop of the current business (tenant scope) and, for a one-shop user, their own.
 */
class CalendarFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'shopId' => ['required', 'string', 'regex:/\A[0-9A-Za-z]{26}\z/'],
            'userId' => ['required', 'string', 'max:64', 'regex:/\A[0-9A-Za-z]+\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shopId.required' => 'Choose the shop.',
            'shopId.regex' => 'Choose the shop.',
            'userId.required' => 'Choose the stylist.',
            'userId.regex' => 'Choose the stylist.',
        ];
    }

    public function shop(): Branch
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $shop = Branch::query()->active()->find($this->validated('shopId'));

        if ($shop === null || ($restricted !== null && $shop->id !== $restricted)) {
            throw ValidationException::withMessages(['shopId' => 'Choose one of your open salon shops.']);
        }

        return $shop;
    }

    public function stylist(): string
    {
        return (string) $this->validated('userId');
    }
}
