<?php

namespace App\Http\Requests\Api;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;

/**
 * `POST /api/v1/licence/validate` per till (validate-request.schema.json, §17.15.2). `registers` (branch model) is
 * accepted and ignored; `lastSyncAt` decides whether the main till gets a sync key (module 2.1). Module 2.7 keeps the
 * known `diagnostics` members for Till health (pendingSyncRows, lastSyncError, databaseSizeMb); anything else in
 * that open object is dropped, so nothing secret can be stored.
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
            'lastSyncAt' => ['nullable', 'date'],
            'diagnostics' => ['nullable', 'array'],
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
            'lastSyncAt' => ApiDate::parse($this->validated('lastSyncAt')),
            'diagnostics' => self::diagnostics($this->validated('diagnostics')),
        ]);
    }

    /**
     * @return array{pendingSyncRows?: int, lastSyncError?: string, databaseSizeMb?: float}|null
     */
    private static function diagnostics(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        $pending = $value['pendingSyncRows'] ?? null;
        $error = $value['lastSyncError'] ?? null;
        $size = $value['databaseSizeMb'] ?? null;

        if (is_int($pending) && $pending >= 0) {
            $out['pendingSyncRows'] = min($pending, 4294967295);
        }

        if (is_string($error) && trim($error) !== '') {
            $out['lastSyncError'] = mb_substr(trim($error), 0, 500);
        }

        if ((is_int($size) || is_float($size)) && $size >= 0) {
            $out['databaseSizeMb'] = round((float) $size, 1);
        }

        return $out;
    }
}
