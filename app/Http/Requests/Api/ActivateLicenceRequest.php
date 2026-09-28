<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;

/**
 * `POST /api/v1/licence/activate` (licence-activate-request.schema.json). The key is checked by the action, so a
 * malformed key is a wrong key (404 key.not_found), not a 400.
 */
class ActivateLicenceRequest extends TillApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'licenceKey' => ['required', 'string', 'max:64'],
            'installId' => ['required', 'string', self::ULID],
            'installCode' => ['required', 'string', self::INSTALL_CODE],
            ...$this->tillRules(),
            'deviceName' => ['required', 'string', 'max:100'],
            'existingIds' => ['required', 'array'],
            'existingIds.companyId' => ['required', 'string', self::ULID],
            'existingIds.branchId' => ['required', 'string', self::ULID],
            'existingIds.registerId' => ['required', 'string', self::ULID],
        ];
    }

    public function licenceKey(): string
    {
        return (string) $this->validated('licenceKey');
    }

    /**
     * @throws ApiException request.invalid
     */
    public function tillRequest(): TillRequest
    {
        $ids = (array) $this->validated('existingIds');

        return $this->till(['existingIds' => [
            'companyId' => (string) $ids['companyId'],
            'branchId' => (string) $ids['branchId'],
            'registerId' => (string) $ids['registerId'],
        ]]);
    }
}
