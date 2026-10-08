<?php

namespace App\Http\Requests\App\Pricing;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\LocalText;
use App\Domain\Shared\Country\TillProfile;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sets (POST …/shop) or ends (POST …/shop/end, `end` route default) one shop's own price (module 4.3). Route:
 * `company.can:prices.manage`. A one-shop user may name only their own shop (403 otherwise). Times are typed in
 * London time (`datetime-local`) and stored in UTC.
 */
class ShopPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || $this->input('branch_id') === $restricted;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ending = $this->routeIs('app.prices.shop.end');

        return [
            'branch_id' => ['required', 'string', 'size:26'],
            'product_unit_id' => ['nullable', 'string', 'size:26'],
            'price' => $ending ? ['prohibited'] : ['required', TillProfile::priceRule()],
            'valid_from' => $ending ? ['prohibited'] : ['nullable', 'date'],
            'valid_to' => $ending ? ['prohibited'] : ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['price.regex' => LocalText::currency('Enter the price in pounds, e.g. 1.39.')];
    }

    public function time(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, Country::zone())->utc() : null;
    }
}
