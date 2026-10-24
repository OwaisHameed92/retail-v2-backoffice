<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Support\StoreImages;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * One home banner (OS-8), sent as a form with its photo: headline, short text, button label, where the button goes,
 * on or off, `remove_photo`. The route checks `store.manage`; a one-shop user may not change the business's store
 * (403). The fields themselves are checked by SaveStoreBanner; the photo path never comes from the browser.
 */
class SaveStoreBannerRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'headline' => ['nullable', 'string', 'max:160'],
            'text' => ['nullable', 'string', 'max:240'],
            'button_label' => ['nullable', 'string', 'max:40'],
            'link_type' => ['nullable', 'string', 'max:20'],
            'link_target' => ['nullable', 'string', 'max:500'],
            'is_on' => ['sometimes', 'boolean'],
            'remove_photo' => ['sometimes', 'boolean'],
            'photo' => StoreImages::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreImages::messages('photo');
    }

    /**
     * The banner's fields for SaveStoreBanner (no file).
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return [
            ...$this->safe()->only(['headline', 'text', 'button_label', 'link_type', 'link_target']),
            'is_on' => $this->has('is_on') ? $this->boolean('is_on') : true,
            'remove_photo' => $this->boolean('remove_photo'),
        ];
    }
}
