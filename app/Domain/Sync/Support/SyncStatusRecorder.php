<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Models\SyncBranchStatus;
use App\Domain\TillData\Sync\Data\ApplyResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps `sync_branch_status` (module 2.7 reads it): one row per branch, written after each hello, push and pull.
 * Push writes happen inside the branch's push lock, so the day counters never race.
 */
final class SyncStatusRecorder
{
    public function hello(SyncCaller $caller, string $appVersion, string $tillRegisterId): void
    {
        $this->write($caller, ['last_hello_at' => now('UTC'), ...$this->till($caller, $appVersion, $tillRegisterId)]);
    }

    public function pushed(SyncCaller $caller, string $stream, ApplyResult $result, string $appVersion, string $tillRegisterId): void
    {
        $now = now('UTC');
        $values = [
            'last_push_at' => $now,
            ...$this->till($caller, $appVersion, $tillRegisterId),
            ...$this->counts($caller, $result->accepted, count($result->rejected)),
            ...($stream === ''
                ? ['last_acknowledged_seq' => $result->acknowledgedSeq]
                : ['last_upload_id' => $stream, 'last_upload_seq' => $result->acknowledgedSeq]),
        ];

        if (($first = $result->firstRejection()) !== null) {
            $values += $this->error('row.invalid', "{$first->code}: {$first->message}", $first->key);
        }

        $this->write($caller, $values);
    }

    public function pulled(SyncCaller $caller, int $since, int $highestVersion, int $rows, string $appVersion, string $tillRegisterId): void
    {
        $this->write($caller, [
            'last_pull_at' => now('UTC'),
            'last_pull_since' => $since,
            'last_pull_version' => $highestVersion,
            'last_pull_rows' => $rows,
            ...$this->till($caller, $appVersion, $tillRegisterId),
        ]);
    }

    public function failed(SyncCaller $caller, ApiException $error, string $appVersion, string $tillRegisterId): void
    {
        $this->write($caller, [
            ...$this->till($caller, $appVersion, $tillRegisterId),
            ...$this->error($error->errorCode, $error->getMessage(), $error->rejectedKey),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function till(SyncCaller $caller, string $appVersion, string $tillRegisterId): array
    {
        return array_filter([
            'last_app_version' => Str::limit($appVersion, 40, ''),
            'last_register_id' => $caller->registerId,
            'last_till_register_id' => Str::limit($tillRegisterId, 64, ''),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function counts(SyncCaller $caller, int $accepted, int $rejected): array
    {
        $today = now('Europe/London')->toDateString();
        $row = DB::table('sync_branch_status')->where('branch_id', $caller->branch->id)->first(['rows_day', 'rows_accepted_today', 'rows_rejected_today']);
        $same = $row !== null && substr((string) $row->rows_day, 0, 10) === $today;

        return [
            'rows_day' => $today,
            'rows_accepted_today' => ($same ? (int) $row->rows_accepted_today : 0) + $accepted,
            'rows_rejected_today' => ($same ? (int) $row->rows_rejected_today : 0) + $rejected,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $code, string $message, ?string $rejectedKey): array
    {
        return [
            'last_error_at' => now('UTC'),
            'last_error_code' => Str::limit($code, 64, ''),
            'last_error_message' => Str::limit($message, 500, ''),
            'last_rejected_key' => $rejectedKey === null ? null : Str::limit($rejectedKey, 160, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(SyncCaller $caller, array $values): void
    {
        $now = now('UTC');

        DB::table('sync_branch_status')->insertOrIgnore([
            'id' => Ulid::new(),
            'company_id' => $caller->company->id,
            'branch_id' => $caller->branch->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        SyncBranchStatus::withoutCompanyScope()->where('branch_id', $caller->branch->id)->update([...$values, 'updated_at' => $now]);
    }
}
