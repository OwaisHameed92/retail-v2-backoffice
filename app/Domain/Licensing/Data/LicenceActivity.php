<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Admin\Models\Admin;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Readable audit log rows for licences: the licence page's "Activity" card, and licence entries on the tenant
 * page (TenantActivity delegates `licence.*` actions here).
 */
final class LicenceActivity
{
    public const TIMEZONE = 'Europe/London';

    /**
     * Activity rows of one licence, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forLicence(Licence $licence, int $limit = 50): array
    {
        $entries = AuditLog::query()
            ->where('subject_type', $licence->getMorphClass())
            ->where('subject_id', $licence->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $actors = self::actorNames($entries);

        return $entries->map(fn (AuditLog $entry) => [
            'id' => $entry->id,
            'action' => $entry->action,
            'description' => self::describe($entry, null),
            'actorName' => $actors[$entry->actor_type.':'.$entry->actor_id] ?? ($entry->actor_type === null ? 'System' : 'Former user'),
            'createdAt' => $entry->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * One sentence for a licence audit entry. `$till` names the till ("Till 2 (02) at Leeds") on tenant-wide
     * activity; null on the licence's own page.
     */
    public static function describe(AuditLog $entry, ?string $till): string
    {
        $meta = $entry->meta ?? [];
        $before = $entry->before ?? [];
        $after = $entry->after ?? [];
        $of = $till === null ? '' : " for {$till}";
        $reason = fn () => isset($meta['reason']) && $meta['reason'] !== '' ? ': '.$meta['reason'] : '';
        $status = fn (mixed $value) => LicenceStatus::tryFrom((string) $value)?->label() ?? (string) $value;

        return match ($entry->action) {
            'licence.issued' => 'Issued the licence'.$of.' (key ending '.($after['key_last4'] ?? '····').', plan '.($after['plan'] ?? '').')',
            'licence.key_reissued' => 'Replaced the key'.$of.': ending '.($before['key_last4'] ?? '····').' no longer works, new key ends '.($after['key_last4'] ?? '····'),
            'licence.device_reset' => 'Reset the PC'.$of.' (was '.($before['device_name'] ?? $before['device_id'] ?? 'unknown').')',
            'licence.suspended' => 'Suspended the licence'.$of.$reason(),
            'licence.unsuspended' => 'Lifted the suspension'.$of.' (now '.strtolower($status($after['status'] ?? '')).')',
            'licence.revoked' => 'Revoked the licence'.$of.$reason(),
            'licence.renewed' => 'Renewed the licence'.$of.' until '.self::date($after['expires_at'] ?? null),
            'licence.plan_changed' => 'Moved the licence'.$of.' from '.($meta['from_plan_name'] ?? $before['plan'] ?? 'its plan').' to '.($meta['to_plan_name'] ?? $after['plan'] ?? 'a new plan'),
            'licence.status_changed' => 'Licence'.$of.' moved from '.strtolower($status($before['status'] ?? '')).' to '.strtolower($status($after['status'] ?? '')),
            'licence.key_emailed' => 'Emailed the key'.$of.' to the owner',
            'licence.notes_updated' => 'Updated the notes'.$of,
            // Till API (module 1.5).
            'licence.activated' => 'Activated'.$of.' on '.self::pc($after).(isset($after['trial_ends_at']) ? ', trial until '.self::date($after['trial_ends_at']) : ''),
            'licence.device_bound' => 'Activated'.$of.' on a new PC, '.self::pc($after),
            'licence.reinstalled' => 'Reinstalled'.$of.' on '.self::pc($after),
            'licence.released' => 'Released the key'.$of.' from '.self::pc($before).' (deactivated on the till)',
            'licence.app_updated' => 'Updated SSPOS'.$of.' from '.($before['last_app_version'] ?? '?').' to '.($after['last_app_version'] ?? '?'),
            'licence.alert_resolved' => 'Resolved the alert "'.($meta['label'] ?? $after['alert'] ?? 'alert').'"'.$of,
            default => $entry->action,
        };
    }

    /**
     * "admin:<id>" / "user:<id>" → display name.
     *
     * @param  Collection<int, AuditLog>  $entries
     * @return array<string, string>
     */
    private static function actorNames(Collection $entries): array
    {
        $names = [];
        $adminType = (new Admin)->getMorphClass();
        $userType = (new User)->getMorphClass();

        $adminIds = $entries->where('actor_type', $adminType)->pluck('actor_id')->filter()->unique()->all();
        foreach (Admin::query()->whereIn('id', $adminIds)->get(['id', 'name']) as $admin) {
            $names[$adminType.':'.$admin->id] = $admin->name.' (Switch & Save)';
        }

        $userIds = $entries->where('actor_type', $userType)->pluck('actor_id')->filter()->unique()->all();
        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'name']) as $user) {
            $names[$userType.':'.$user->id] = $user->name;
        }

        $registerType = (new Register)->getMorphClass();
        $registerIds = $entries->where('actor_type', $registerType)->pluck('actor_id')->filter()->unique()->all();
        foreach (Register::withoutCompanyScope()->withTrashed()->whereIn('id', $registerIds)->get(['id', 'name', 'code']) as $register) {
            $names[$registerType.':'.$register->id] = "{$register->name} ({$register->code}) via the till";
        }

        return $names;
    }

    /**
     * "FRONT-TILL" (the PC name, else the end of its id).
     *
     * @param  array<string, mixed>  $values
     */
    private static function pc(array $values): string
    {
        $name = $values['device_name'] ?? null;
        $id = $values['device_id'] ?? null;

        return match (true) {
            is_string($name) && $name !== '' => $name,
            is_string($id) && $id !== '' => 'PC …'.mb_substr($id, -4),
            default => 'a PC',
        };
    }

    private static function date(mixed $iso): string
    {
        if (! is_string($iso) || $iso === '') {
            return 'a new date';
        }

        return CarbonImmutable::parse($iso)->setTimezone(self::TIMEZONE)->format('j M Y');
    }
}
