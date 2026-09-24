<?php

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/licence/check-in`: licenceKey, deviceId, appVersion, tokenId, lastSaleAt, requestedAt.
 */
class CheckInLicenceRequest extends LicenceApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->keyAndDeviceRules() + [
            'appVersion' => ['nullable', 'string', 'max:50'],
            'tokenId' => ['nullable', 'string', 'max:64'],
            'lastSaleAt' => ['nullable', 'string', 'date'],
            'requestedAt' => ['nullable', 'string', 'date'],
        ];
    }
}
