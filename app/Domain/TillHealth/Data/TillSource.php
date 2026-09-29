<?php

namespace App\Domain\TillHealth\Data;

use App\Domain\TillHealth\Queries\HealthSources;
use Carbon\CarbonImmutable;

/**
 * One till as Till health reads it (module 2.7): the active register and its live licence (if any), from one
 * joined query ({@see HealthSources}). Dates are UTC.
 */
final readonly class TillSource
{
    public function __construct(
        public string $companyId,
        public string $companyStatus,
        public string $branchId,
        public string $registerId,
        public bool $isMainTill,
        public ?string $licenceId = null,
        public ?string $licenceStatus = null,
        public ?string $installId = null,
        public ?string $deviceName = null,
        public ?CarbonImmutable $lastCheckInAt = null,
        public ?CarbonImmutable $lastValidatedAt = null,
        public ?string $appVersion = null,
        public ?string $contractVersion = null,
        public ?int $clockSkewSeconds = null,
        public ?int $pendingSyncRows = null,
        public ?CarbonImmutable $diagnosticsAt = null,
        public ?bool $lockLocked = null,
        public ?string $lockReason = null,
    ) {}

    public static function fromRow(object $row): self
    {
        $diagnostics = is_string($row->diagnostics ?? null) ? json_decode($row->diagnostics, true) : null;
        $pending = is_array($diagnostics) && is_int($diagnostics['pendingSyncRows'] ?? null) ? $diagnostics['pendingSyncRows'] : null;

        return new self(
            companyId: (string) $row->company_id,
            companyStatus: (string) $row->company_status,
            branchId: (string) $row->branch_id,
            registerId: (string) $row->register_id,
            isMainTill: (bool) $row->is_main_till,
            licenceId: self::text($row->licence_id ?? null),
            licenceStatus: self::text($row->licence_status ?? null),
            installId: self::text($row->device_id ?? null),
            deviceName: self::text($row->device_name ?? null),
            lastCheckInAt: self::date($row->last_check_in_at ?? null),
            lastValidatedAt: self::date($row->last_validated_at ?? null),
            appVersion: self::text($row->last_app_version ?? null),
            contractVersion: self::text($row->last_contract_version ?? null),
            clockSkewSeconds: isset($row->till_clock_skew_seconds) ? (int) $row->till_clock_skew_seconds : null,
            pendingSyncRows: $pending,
            diagnosticsAt: self::date($row->diagnostics_at ?? null),
            lockLocked: isset($row->lock_locked) ? (bool) $row->lock_locked : null,
            lockReason: self::text($row->lock_reason ?? null),
        );
    }

    /** The key is in use on a PC (bound). */
    public function isActivated(): bool
    {
        return $this->licenceId !== null && $this->installId !== null;
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value, 'UTC');
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
