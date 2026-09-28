<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;

/**
 * `POST /api/v1/licence/validate` per till (validate-request.schema.json, §17.15.2). `registers`, `lastSyncAt`
 * and `diagnostics` (branch model) are accepted and ignored.
 */
class ValidateLicenceRequest extends TillApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'licenceId' => ['required', 'string', self::ULID],
            'tokenSha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'installId' => ['nullable', 'string', self::ULID],
            'installCode' => ['nullable', 'string', self::INSTALL_CODE],
            ...$this->tillRules(),
            'trustedKids' => ['required', 'array', 'min:1'],
            'clockWatermarkUtc' => ['required', 'date'],
            'lastValidatedAtUtc' => ['present', 'nullable', 'date'],
            'lock' => ['required', 'array'],
            'lock.locked' => ['required', 'boolean'],
            'lock.reason' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @throws ApiException request.invalid
     */
    public function tillRequest(): TillRequest
    {
        return $this->till([
            'clockWatermarkUtc' => ApiDate::parse($this->validated('clockWatermarkUtc')),
            'locked' => (bool) $this->validated('lock.locked'),
            'lockReason' => self::text($this->validated('lock.reason')),
        ]);
    }
}
