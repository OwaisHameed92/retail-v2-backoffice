<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;

/**
 * `POST /api/v1/devices/deactivate` (deactivate-request.schema.json). `reason` is an open list (transfer,
 * replaced, removed, closed, other): stored as sent. `tokenSha256` and `installCode` are optional and additive
 * (ANSWERS-2026-10-06 "Purane khule sawal" 2; tills up to 0.1.51 send neither): a `tokenSha256` must be the hash of
 * the token we issued that install (DeactivateDevice).
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
            'tokenSha256' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'installCode' => ['nullable', 'string', self::INSTALL_CODE],
        ];
    }

    /** The SHA-256 of the licence token the till holds, when it sent one. */
    public function tokenSha256(): ?string
    {
        return self::text($this->validated('tokenSha256'));
    }

    /**
     * @throws ApiException request.invalid
     */
    public function tillRequest(): TillRequest
    {
        return $this->till();
    }
}
