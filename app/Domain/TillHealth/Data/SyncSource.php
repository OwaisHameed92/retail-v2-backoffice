<?php

namespace App\Domain\TillHealth\Data;

use Carbon\CarbonImmutable;

/**
 * A shop's `sync_branch_status` row as Till health reads it (module 2.7).
 */
final readonly class SyncSource
{
    public function __construct(
        public ?CarbonImmutable $lastHelloAt = null,
        public ?CarbonImmutable $lastPushAt = null,
        public ?CarbonImmutable $lastPullAt = null,
        public ?CarbonImmutable $lastErrorAt = null,
        public ?string $lastErrorCode = null,
        public ?string $lastErrorMessage = null,
        public ?string $lastRegisterId = null,
        public ?string $lastAppVersion = null,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            lastHelloAt: TillSource::date($row->last_hello_at ?? null),
            lastPushAt: TillSource::date($row->last_push_at ?? null),
            lastPullAt: TillSource::date($row->last_pull_at ?? null),
            lastErrorAt: TillSource::date($row->last_error_at ?? null),
            lastErrorCode: isset($row->last_error_code) ? (string) $row->last_error_code : null,
            lastErrorMessage: isset($row->last_error_message) ? (string) $row->last_error_message : null,
            lastRegisterId: isset($row->last_register_id) ? (string) $row->last_register_id : null,
            lastAppVersion: isset($row->last_app_version) ? (string) $row->last_app_version : null,
        );
    }

    /** The latest hello, push or pull: the shop's last sync contact. */
    public function lastContactAt(): ?CarbonImmutable
    {
        $times = array_filter([$this->lastHelloAt, $this->lastPushAt, $this->lastPullAt]);

        return $times === [] ? null : array_reduce($times, fn (?CarbonImmutable $max, CarbonImmutable $at) => $max === null || $at->greaterThan($max) ? $at : $max);
    }

    public function isLinked(): bool
    {
        return $this->lastContactAt() !== null;
    }
}
