<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;

/**
 * `POST /api/v1/licence/redeem` (redeem-request.schema.json, contract v1.4.1 §17.6). `keyType` is a courtesy and an
 * open value (§17.11 rule 3): the action looks at the key itself. `installId`, device and clock fields are optional
 * (older tills omit them); the install id then comes from `X-SSPOS-Install-Id`.
 */
class RedeemLicenceRequest extends TillApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'min:6', 'max:8192'],
            'keyType' => ['required', 'string', 'max:20'],
            'licenceId' => ['present', 'nullable', 'string', self::ULID],
            'tokenSha256' => ['present', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'installCode' => ['required', 'string', self::INSTALL_CODE],
            'installId' => ['nullable', 'string', self::ULID],
            ...$this->tillRules(),
            'appVersion' => ['nullable', 'string', self::APP_VERSION],
            'tillClockUtc' => ['nullable', 'date'],
            'trustedKids' => ['required', 'array', 'min:1'],
        ];
    }

    public function key(): string
    {
        return (string) $this->validated('key');
    }

    /**
     * @return array{companyId: string|null, branchId: string|null, registerId: string|null}
     */
    public function tillIds(): array
    {
        return [
            'companyId' => self::text($this->header('X-SSPOS-Company-Id')),
            'branchId' => self::text($this->header('X-SSPOS-Branch-Id')),
            'registerId' => self::text($this->header('X-SSPOS-Register-Id')),
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
