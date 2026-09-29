<?php

namespace App\Domain\Sync\Support;

use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Tenancy\Models\Branch;

/**
 * Whether a `licence/activate` or `licence/validate` reply carries the branch's sync key as `apiKey` (+ `hubUrl`),
 * contract v1.4.1 §17.3 step 3 "one code for the dashboard", ANSWERS §2 (module 2.1).
 *
 * Only to the **main till** of an active branch, only while the licence trades and has `cloud_sync`. Plain keys
 * are never stored, so sending a key always means issuing a new one (the old one keeps 7 days' grace):
 *
 * - the branch never had a key → issue (after an admin revoke: nothing until an admin generates one);
 * - an admin asked for a new key at the till's next check → issue;
 * - activate: the key was not sent to this install, or was but never used (a reinstall lost it) → issue;
 * - validate: "the till reports none" = `lastSyncAt` null and the key was not sent to this install (it was
 *   typed on another PC, or the main till moved) → issue;
 * - otherwise nothing (`apiKey` stays out of activate, null in validate).
 */
final class SyncKeyDelivery
{
    public function __construct(private readonly IssueSyncKey $issue) {}

    /**
     * @return array{apiKey?: string, hubUrl?: string}
     */
    public function forActivation(Licence $licence, TillRequest $till, LicenceClaims $claims, string $status): array
    {
        return $this->deliver($licence, $till, $claims, $status, activation: true);
    }

    /**
     * @return array{apiKey?: string, hubUrl?: string}
     */
    public function forValidation(Licence $licence, TillRequest $till, LicenceClaims $claims, string $status): array
    {
        return $this->deliver($licence, $till, $claims, $status, activation: false);
    }

    /** `hubUrl` for replies: config('sync.hub_url') when it is https and not our own address, else null. */
    public static function hubUrl(): ?string
    {
        $hub = rtrim(trim((string) config('sync.hub_url')), '/');

        if ($hub === '' || ! str_starts_with($hub, 'https://') || $hub === rtrim((string) config('app.url'), '/')) {
            return null;
        }

        return $hub;
    }

    /**
     * @return array{apiKey?: string, hubUrl?: string}
     */
    private function deliver(Licence $licence, TillRequest $till, LicenceClaims $claims, string $status, bool $activation): array
    {
        if (! $this->eligible($licence, $claims, $status) || ! $this->needsKey($licence, $till, $activation)) {
            return [];
        }

        /** @var Branch $branch eligible() checked it */
        $branch = $licence->branch;
        $plain = $this->issue->handle($branch, SyncKeySource::Till, installId: $till->installId);
        $hub = self::hubUrl();

        return $hub === null ? ['apiKey' => $plain] : ['apiKey' => $plain, 'hubUrl' => $hub];
    }

    private function eligible(Licence $licence, LicenceClaims $claims, string $status): bool
    {
        $licence->loadMissing(['branch', 'register']);

        return TillStatus::trades($status)
            && in_array(Feature::CloudSync->value, $claims->features, true)
            && $licence->branch?->is_active === true
            && $licence->register?->is_active === true
            && $licence->register->is_main_till;
    }

    private function needsKey(Licence $licence, TillRequest $till, bool $activation): bool
    {
        $keys = SyncKey::withoutCompanyScope()->where('branch_id', $licence->branch_id)->lockForUpdate()->get();
        $current = $keys->first(fn (SyncKey $key) => $key->isCurrent());

        // Without a current key the branch either never had one (issue) or an admin revoked it (wait for an admin).
        if ($current === null) {
            return $keys->isEmpty();
        }

        if ($current->rotate_requested_at !== null) {
            return true;
        }

        $sentHere = $current->delivered_install_id === $till->installId;

        if ($activation) {
            return ! $sentHere || $current->last_used_at === null;
        }

        return ! $sentHere && $till->lastSyncAt === null;
    }
}
