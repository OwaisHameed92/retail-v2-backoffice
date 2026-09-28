<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;

/**
 * `POST /api/v1/devices/deactivate` (deactivate-request.schema.json). `reason` is an open list (transfer,
 * replaced, removed, closed, other): stored as sent.
 */
class DeactivateDeviceRequest extends TillApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'registerId' => ['required', 'string', self::ULID],
            'installId' => ['present', 'nullable', 'string', self::ULID],
            'reason' => ['required', 'string', 'max:40'],
            'note' => ['present', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @throws ApiException request.invalid
     */
    public function tillRequest(): TillRequest
    {
        return $this->till();
    }
}
