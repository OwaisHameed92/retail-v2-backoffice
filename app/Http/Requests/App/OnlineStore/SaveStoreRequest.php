<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Support\StoreImages;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * The store builder's "Save changes" (OS-3), sent as a form with the logo and banner files. The route checks
 * `store.manage`; a one-shop user may look but not change the business's store (403). The settings themselves are
 * checked by SaveOnlineStore; picture paths never come from the browser. OS-8: the featured products setting and the
 * departments with a home row (banners have their own routes, OnlineStoreBannerController). There is no `template` field: the store's
 * look is set by Switch & Save (admin), so one sent here is ignored.
 */
class SaveStoreRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:60'],
            'headline' => ['nullable', 'string', 'max:160'],
            'brand_colour' => ['nullable', 'string', 'max:7'],
            'order_email' => ['nullable', 'string', 'max:255'],
            'sections' => ['required', 'array', 'max:20'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.enabled' => ['required', 'boolean'],
            'featured_mode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'about_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'featured_product_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'featured_product_ids.*' => ['string', 'max:64'],
            'home_departments' => ['sometimes', 'nullable', 'array', 'max:20'],
            'home_departments.*' => ['string', 'max:64'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_banner' => ['sometimes', 'boolean'],
            'logo' => StoreImages::rules(),
            'banner' => StoreImages::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...StoreImages::messages('logo'), ...StoreImages::messages('banner'), 'slug.required' => 'Choose your store address.'];
    }

    /**
     * The settings for SaveStoreBuilder (no files).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->safe()->except(['logo', 'banner']);
        $data['sections'] = array_map(fn (array $s) => ['key' => (string) $s['key'], 'enabled' => filter_var($s['enabled'], FILTER_VALIDATE_BOOLEAN)], (array) $data['sections']);
        $data['remove_logo'] = $this->boolean('remove_logo');
        $data['remove_banner'] = $this->boolean('remove_banner');

        foreach (['featured_product_ids', 'home_departments'] as $list) {
            if ($this->has($list) || $this->has("{$list}_sent")) {
                $data[$list] = array_values((array) ($data[$list] ?? []));
            }
        }

        return $data;
    }
}
