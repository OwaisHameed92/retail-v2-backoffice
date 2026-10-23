<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Support\StoreImages;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * The store builder's "Save changes" (OS-3), sent as a form with the logo and banner files. The route checks
 * `store.manage`; a one-shop user may look but not change the business's store (403). The settings themselves are
 * checked by SaveOnlineStore; picture paths never come from the browser.
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
            'template' => ['required', 'string', 'max:40'],
            'headline' => ['nullable', 'string', 'max:160'],
            'brand_colour' => ['nullable', 'string', 'max:7'],
            'order_email' => ['nullable', 'string', 'max:255'],
            'sections' => ['required', 'array', 'max:20'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.enabled' => ['required', 'boolean'],
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

        return $data;
    }
}
