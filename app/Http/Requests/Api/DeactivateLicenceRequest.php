<?php

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/licence/deactivate`: licenceKey, deviceId.
 */
class DeactivateLicenceRequest extends LicenceApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->keyAndDeviceRules();
    }
}
