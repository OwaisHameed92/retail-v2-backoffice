<?php

namespace App\Http\Requests\Api;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Data\MigrationInput;

/**
 * `POST /api/v1/cloud/migrate` (migrate-request.schema.json, contract v1.4.1 §17.8). The code is checked by the action
 * (a wrong code is 404, not 400). The main till's register id: the reported register marked `isMain`, else
 * `X-SSPOS-Register-Id`, else the first one reported.
 */
class MigrateRequest extends TillApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'activationCode' => ['required', 'string', 'max:64'],
            'installId' => ['required', 'string', self::ULID],
            'installCode' => ['required', 'string', self::INSTALL_CODE],
            ...$this->tillRules(),
            'deviceName' => ['required', 'string', 'max:100'],
            'trustedKids' => ['required', 'array', 'min:1'],
            'localLicenceToken' => ['present', 'nullable', 'string', 'max:8192'],
            'localTrialEndsAt' => ['present', 'nullable', 'date'],
            'company' => ['required', 'array'],
            'company.id' => ['required', 'string', self::ULID],
            'company.name' => ['present', 'nullable', 'string', 'max:200'],
            'branch' => ['required', 'array'],
            'branch.id' => ['required', 'string', self::ULID],
            'branch.name' => ['present', 'nullable', 'string', 'max:200'],
            'branch.code' => ['present', 'nullable', 'string', 'max:6'],
            'registers' => ['required', 'array', 'min:1'],
            'registers.*.registerId' => ['required', 'string', self::ULID],
            'registers.*.isMain' => ['required', 'boolean'],
            'registers.*.isActive' => ['nullable', 'boolean'],
            'data' => ['required', 'array'],
            'data.totalRows' => ['required', 'integer', 'min:0'],
            'data.rowCounts' => ['present', 'array'],
            'data.rowCounts.*' => ['integer', 'min:0'],
            'data.firstSaleAtUtc' => ['nullable', 'date'],
            'data.lastSaleAtUtc' => ['nullable', 'date'],
            'data.snapshotChangeLogSeq' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @throws ApiException request.invalid
     */
    public function migrationInput(): MigrationInput
    {
        $registers = array_map(fn (array $r) => [
            'registerId' => (string) $r['registerId'],
            'isMain' => (bool) $r['isMain'],
            'isActive' => (bool) ($r['isActive'] ?? true),
        ], array_values((array) $this->validated('registers')));
        $main = collect($registers)->firstWhere('isMain', true)['registerId'] ?? self::text($this->header('X-SSPOS-Register-Id')) ?? $registers[0]['registerId'];
        $counts = array_map('intval', array_filter((array) $this->validated('data.rowCounts'), fn ($v, $k) => is_string($k) && is_int($v), ARRAY_FILTER_USE_BOTH));

        return new MigrationInput(
            activationCode: strtoupper(trim((string) $this->validated('activationCode'))),
            till: $this->till(['existingIds' => [
                'companyId' => (string) $this->validated('company.id'),
                'branchId' => (string) $this->validated('branch.id'),
                'registerId' => (string) $main,
            ]]),
            localLicenceToken: self::text($this->validated('localLicenceToken')),
            companyName: (string) self::text($this->validated('company.name')),
            branchName: (string) self::text($this->validated('branch.name')),
            registers: $registers,
            totalRows: (int) $this->validated('data.totalRows'),
            rowCounts: $counts,
            firstSaleAt: ApiDate::parse($this->validated('data.firstSaleAtUtc')),
            lastSaleAt: ApiDate::parse($this->validated('data.lastSaleAtUtc')),
            snapshotChangeLogSeq: (int) $this->validated('data.snapshotChangeLogSeq'),
        );
    }
}
