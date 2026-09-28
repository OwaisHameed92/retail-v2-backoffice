<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use Carbon\CarbonImmutable;

/**
 * Shapes licences for the admin Inertia pages and JSON replies (camelCase keys, ISO-8601 UTC dates).
 * Never includes the key hash; the plain key appears only in issuedKey(), for the reply that created it.
 */
final class LicenceData
{
    /**
     * A row of the licence tables (admin list, tenant tab).
     *
     * @return array<string, mixed>
     */
    public static function row(Licence $licence, CarbonImmutable $now): array
    {
        $state = LicenceState::for($licence, $now);

        return [
            'id' => $licence->id,
            'maskedKey' => $licence->maskedKey(),
            'keyLast4' => $licence->key_last4,
            'status' => $state->status->value,
            'statusLabel' => $state->status->label(),
            'statusReason' => $state->reason,
            'storedStatus' => $licence->status->value,
            'isTrial' => $state->isTrial,
            'company' => ['id' => $licence->company_id, 'name' => $licence->company->name ?? 'Deleted business'],
            'branch' => ['id' => $licence->branch_id, 'name' => $licence->branch->name ?? 'Deleted branch', 'code' => $licence->branch?->code],
            'register' => [
                'id' => $licence->register_id,
                'name' => $licence->register->name ?? 'Deleted till',
                'code' => $licence->register?->code,
                'isMainTill' => (bool) $licence->register?->is_main_till,
            ],
            'plan' => self::plan($licence->plan),
            'activatedAt' => self::date($licence->activated_at),
            'trialEndsAt' => self::date($licence->trial_ends_at),
            'expiresAt' => self::date($licence->expires_at),
            'endsAt' => self::date($state->endsAt),
            'graceEndsAt' => self::date($state->graceEndsAt),
            'deviceId' => $licence->device_id,
            'deviceName' => $licence->device_name,
            'boundAt' => self::date($licence->bound_at),
            'lastCheckInAt' => self::date($licence->last_check_in_at),
            'lastAppVersion' => $licence->last_app_version,
            'createdAt' => self::date($licence->created_at),
        ];
    }

    /**
     * Everything the licence page shows.
     *
     * @return array<string, mixed>
     */
    public static function detail(Licence $licence, CarbonImmutable $now): array
    {
        return self::row($licence, $now) + [
            'features' => $licence->features->map(fn (Feature $feature) => ['value' => $feature->value, 'label' => $feature->label()])->values()->all(),
            'graceDays' => $licence->grace_days,
            'lastIp' => $licence->last_ip,
            // Contract v1.3.1 §17.15.3: what the till reported (deviceId holds its installId).
            'installCode' => $licence->install_code,
            'os' => $licence->os === null ? null : (trim(implode(' ', array_filter([$licence->os['name'] ?? null, $licence->os['version'] ?? null, $licence->os['architecture'] ?? null]))) ?: null),
            'tillClockSkewSeconds' => $licence->till_clock_skew_seconds,
            'lastValidatedAt' => self::date($licence->last_validated_at),
            'lock' => $licence->lock_locked === null ? null : ['locked' => $licence->lock_locked, 'reason' => $licence->lock_reason],
            'suspendedAt' => self::date($licence->suspended_at),
            'suspendedReason' => $licence->suspended_reason,
            'revokedAt' => self::date($licence->revoked_at),
            'revokedReason' => $licence->revoked_reason,
            'notes' => $licence->notes,
            'isRevoked' => $licence->isRevoked(),
            'isSuspended' => $licence->status === LicenceStatus::Suspended,
            'isBound' => $licence->isBound(),
            'updatedAt' => self::date($licence->updated_at),
        ];
    }

    /**
     * The plain key for the admin's one-time "Licence key created" dialog. Only in the reply that created it.
     *
     * @return array<string, mixed>
     */
    public static function issuedKey(IssuedLicence $issued): array
    {
        $licence = $issued->licence;

        return [
            'licenceId' => $licence->id,
            'key' => $issued->plainKey(),
            'keyLast4' => $licence->key_last4,
            'companyId' => $licence->company_id,
            'businessName' => $licence->company?->name,
            'branchName' => $licence->branch?->name,
            'tillName' => $licence->register?->name,
            'tillCode' => $licence->register?->code,
            'replacedKey' => $issued->replacedKey,
        ];
    }

    /**
     * @param  list<IssuedLicence>  $issued
     * @return list<array<string, mixed>>
     */
    public static function issuedKeys(array $issued): array
    {
        return array_map(fn (IssuedLicence $item) => self::issuedKey($item), $issued);
    }

    /**
     * @return array{id: string, name: string, code: string, archived: bool}|null
     */
    public static function plan(?Plan $plan): ?array
    {
        return $plan === null ? null : ['id' => $plan->id, 'name' => $plan->name, 'code' => $plan->code, 'archived' => $plan->trashed()];
    }

    /**
     * Active plans a licence can move to, for the plan pickers.
     *
     * @return list<array{value: string, label: string, description: string|null}>
     */
    public static function planOptions(): array
    {
        return Plan::query()->where('is_active', true)->ordered()->get()
            ->map(fn (Plan $plan) => [
                'value' => $plan->id,
                'label' => $plan->name,
                'description' => count($plan->features).' features · '.$plan->trial_days.'-day trial · '.$plan->grace_days.' grace days',
            ])->values()->all();
    }

    public static function date(?\DateTimeInterface $date): ?string
    {
        return $date === null ? null : CarbonImmutable::instance($date)->utc()->toIso8601String();
    }
}
