<?php

namespace App\Http\Requests\App\ShopSettings;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Saving the Till settings page (module 4.9). Route: `company.can:settings.manage`. `shop` empty = every shop; a
 * one-shop user may only change their own shop's settings (403 otherwise). Values are checked per setting by
 * SaveShopSettings (SettingCatalogue).
 */
class ShopSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || $this->input('shop') === $restricted;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shop' => ['nullable', 'string', 'size:26'],
            'values' => ['required', 'array', 'max:200'],
        ];
    }

    /**
     * The shop being changed, or null for every shop. Another business's shop is not found (company scope).
     *
     * @throws ValidationException
     */
    public function shop(): ?Branch
    {
        $id = $this->validated('shop');

        if ($id === null || $id === '') {
            return null;
        }

        return Branch::query()->active()->find($id) ?? throw ValidationException::withMessages(['shop' => 'Choose one of your shops.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return (array) $this->validated('values');
    }
}
