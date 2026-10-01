<?php

namespace App\Domain\Audit\Support;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shapes audit entries for the screens and the CSV: who (names looked up in one query per type), which business,
 * what (a readable action and subject), and a field-by-field before/after diff.
 *
 * Tenant view: Switch & Save staff appear as "Switch & Save" with no IP or browser (their details are ours).
 */
final class AuditPresenter
{
    public const STAFF_LABEL = 'Switch & Save';

    public function __construct(private readonly bool $tenantView) {}

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $rows): array
    {
        $adminType = (new Admin)->getMorphClass();
        $userType = (new User)->getMorphClass();

        $admins = Admin::query()->whereIn('id', $this->ids($rows, $adminType))->pluck('name', 'id');
        $users = User::query()->whereIn('id', $this->ids($rows, $userType))->get(['id', 'name', 'email'])->keyBy('id');
        $companies = $this->tenantView ? collect() : Company::withTrashed()->whereIn('id', $rows->pluck('company_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        return $rows->map(function (AuditLog $log) use ($adminType, $userType, $admins, $users, $companies) {
            $isStaff = $log->actor_type === $adminType;
            $actor = match (true) {
                $log->actor_type === null => ['type' => 'system', 'id' => null, 'name' => 'System', 'detail' => 'Automatic'],
                $isStaff && $this->tenantView => ['type' => 'staff', 'id' => null, 'name' => self::STAFF_LABEL, 'detail' => 'Support team'],
                $isStaff => ['type' => 'admin', 'id' => $log->actor_id, 'name' => $admins[$log->actor_id] ?? 'Removed admin', 'detail' => 'Switch & Save staff'],
                $log->actor_type === $userType => [
                    'type' => 'user',
                    'id' => $log->actor_id,
                    'name' => $users[$log->actor_id]->name ?? 'Removed user',
                    'detail' => $users[$log->actor_id]->email ?? null,
                ],
                default => ['type' => 'other', 'id' => $log->actor_id, 'name' => AuditLabels::type($log->actor_type), 'detail' => $log->actor_id],
            };
            $hideNetwork = $this->tenantView && $isStaff;

            return [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601ZuluString(),
                'action' => $log->action,
                'actionLabel' => AuditLabels::action($log->action),
                'actor' => $actor,
                'company' => $this->tenantView || $log->company_id === null ? null : ['id' => $log->company_id, 'name' => $companies[$log->company_id] ?? 'Removed business'],
                'subject' => $log->subject_type === null ? null : [
                    'type' => $log->subject_type,
                    'label' => AuditLabels::type($log->subject_type),
                    'id' => $log->subject_id,
                ],
                'changes' => self::changes($log->before, $log->after),
                'meta' => self::meta($log->meta),
                'ip' => $hideNetwork ? null : $log->ip,
                'userAgent' => $hideNetwork ? null : $log->user_agent,
            ];
        })->values()->all();
    }

    /**
     * Field-by-field diff. A field only on one side is shown as added or removed; unchanged (or empty on both sides)
     * fields are left out.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<array{field: string, label: string, before: string|null, after: string|null}>
     */
    public static function changes(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $out = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $from = array_key_exists($field, $before) ? self::value($before[$field]) : null;
            $to = array_key_exists($field, $after) ? self::value($after[$field]) : null;

            if ($from === $to) {
                continue;
            }

            $out[] = ['field' => (string) $field, 'label' => AuditLabels::field((string) $field), 'before' => $from, 'after' => $to];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return list<array{key: string, label: string, value: string|null}>
     */
    public static function meta(?array $meta): array
    {
        $out = [];

        foreach ($meta ?? [] as $key => $value) {
            $out[] = ['key' => (string) $key, 'label' => AuditLabels::field((string) $key), 'value' => self::value($value)];
        }

        return $out;
    }

    public static function value(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return list<string>
     */
    private function ids(Collection $rows, string $type): array
    {
        return $rows->where('actor_type', $type)->pluck('actor_id')->filter()->unique()->map(fn ($id) => (string) $id)->values()->all();
    }
}
