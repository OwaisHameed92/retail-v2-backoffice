<?php

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/licence/activate`: licenceKey, deviceId, deviceName, appVersion, os, requestedAt.
 */
class ActivateLicenceRequest extends LicenceApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->keyAndDeviceRules() + [
            'deviceName' => ['nullable', 'string', 'max:191'],
            'appVersion' => ['nullable', 'string', 'max:50'],
            'os' => ['nullable', 'string', 'max:100'],
            'requestedAt' => ['nullable', 'string', 'date'],
        ];
    }
}
