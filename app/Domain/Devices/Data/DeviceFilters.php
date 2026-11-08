<?php

namespace App\Domain\Devices\Data;

use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Shared\Support\AppVersion;
use Illuminate\Http\Request;

/**
 * The admin Devices page filters, read leniently from the query string (an unknown value is ignored, never an
 * error): `kind` (till, handheld), `status` (a DeviceStatus; one of the other kind is dropped), `old=1` with
 * `below` (a version; default the highest EPOS version seen), `unseen=1` with `days` (1–365, default 7).
 * `search`, `sort` and paging are TableQuery's.
 */
final readonly class DeviceFilters
{
    public const DEFAULT_DAYS = 7;

    public function __construct(
        public ?DeviceKind $kind = null,
        public ?DeviceStatus $status = null,
        public bool $oldVersion = false,
        /** The version typed by the admin (valid semver), or null for the highest seen. */
        public ?string $below = null,
        public bool $notSeen = false,
        public int $days = self::DEFAULT_DAYS,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $kind = DeviceKind::tryFrom((string) $request->query('kind'));
        $status = DeviceStatus::tryFrom((string) $request->query('status'));

        if ($status !== null && $kind !== null && $status->kind() !== $kind) {
            $status = null;
        }

        $below = trim(mb_substr((string) $request->query('below'), 0, 50));
        $days = (int) $request->query('days', (string) self::DEFAULT_DAYS);

        return new self(
            kind: $kind,
            status: $status,
            oldVersion: $request->boolean('old'),
            below: $below !== '' && AppVersion::isValid($below) ? $below : null,
            notSeen: $request->boolean('unseen'),
            days: $days >= 1 && $days <= 365 ? $days : self::DEFAULT_DAYS,
        );
    }

    /** Whether tills are listed (no kind, or tills) given the status and version filters. */
    public function includesTills(): bool
    {
        return $this->kind !== DeviceKind::Handheld && ($this->status === null || $this->status->kind() === DeviceKind::Till);
    }

    /** Handhelds report no EPOS version, so the version filter lists tills only. */
    public function includesHandhelds(): bool
    {
        return $this->kind !== DeviceKind::Till && ! $this->oldVersion && ($this->status === null || $this->status->kind() === DeviceKind::Handheld);
    }

    /**
     * @return array{kind: string|null, status: string|null, old: bool, below: string|null, unseen: bool, days: int}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind?->value,
            'status' => $this->status?->value,
            'old' => $this->oldVersion,
            'below' => $this->below,
            'unseen' => $this->notSeen,
            'days' => $this->days,
        ];
    }
}
