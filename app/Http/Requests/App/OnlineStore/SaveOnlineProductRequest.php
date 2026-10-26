<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * One product's online details (OS-3), sent as a form with the photo: shown or hidden, short description, photo or
 * `remove_photo`. The route checks `store.manage`; a one-shop user may not change what every shop shows (403).
 */
class SaveOnlineProductRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hidden' => ['sometimes', 'boolean'],
            'not_featured' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.SetOnlineProduct::DESCRIPTION_MAX],
            'allergen_info' => ['sometimes', 'nullable', 'string', 'max:'.SetOnlineProduct::DESCRIPTION_MAX],
            'remove_photo' => ['sometimes', 'boolean'],
            'photo' => StoreImages::rules(),
            // OS-13: the shop's own stock (businesses without tills).
            'out_of_stock' => ['sometimes', 'boolean'],
            'stock_count' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:'.SetOnlineProduct::STOCK_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...StoreImages::messages('photo'), 'description.max' => 'Keep the description to '.SetOnlineProduct::DESCRIPTION_MAX.' characters or fewer.',
            'allergen_info.max' => 'Keep the ingredients and allergens to '.SetOnlineProduct::DESCRIPTION_MAX.' characters or fewer.',
            'stock_count.numeric' => 'Enter the stock as a number, or leave it blank.', 'stock_count.min' => 'The stock cannot be below 0.',
            'stock_count.max' => 'Enter up to '.SetOnlineProduct::STOCK_MAX.'.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = $this->safe()->only(['description', 'allergen_info']);

        if ($this->has('stock_count')) {
            $changes['stock_count'] = $this->filled('stock_count') ? (string) $this->input('stock_count') : null;
        }

        foreach (['hidden', 'not_featured', 'remove_photo', 'out_of_stock'] as $key) {
            if ($this->has($key)) {
                $changes[$key] = $this->boolean($key);
            }
        }

        return $changes;
    }
}
