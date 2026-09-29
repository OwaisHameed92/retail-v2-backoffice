<?php

namespace App\Http\Requests\Api;

/**
 * `POST /api/v1/cloud/migrate/complete` (migrate-complete-request.schema.json, contract v1.4.1 §17.8).
 */
class CompleteMigrationRequest extends TillApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'uploadId' => ['required', 'string', self::ULID],
            'totalRows' => ['required', 'integer', 'min:0'],
            'highestSeq' => ['required', 'integer', 'min:0'],
            'rowCounts' => ['present', 'array'],
            'rowCounts.*' => ['integer', 'min:0'],
            'snapshotChangeLogSeq' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        return array_map('intval', array_filter((array) $this->validated('rowCounts'), fn ($v, $k) => is_string($k) && is_int($v), ARRAY_FILTER_USE_BOTH));
    }
}
