<?php

namespace App\Domain\Staff\Queries;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\Staff\Models\StaffBranch;
use App\Domain\Staff\Support\TillPermissionCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillRolePermission;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Http\Request;

/**
 * Props for the till staff screens (module 4.5): the list, the add/edit form and the role permission editor.
 * A PIN hash or fob code never leaves the server: only "has a PIN" / "has a fob". Runs inside the company scope.
 */
final class StaffScreens
{
    /** @return array<string, mixed> */
    public static function index(Request $request): array
    {
        $roles = TillRole::query()->orderByDesc('level')->orderBy('name')->get(['id', 'name', 'level', 'is_system']);
        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);
        $status = in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : 'all';
        $role = $roles->firstWhere('id', $request->query('role'))?->id;
        $branch = $branches->firstWhere('id', $request->query('branch'))?->id;
        $roleNames = $roles->pluck('name', 'id')->all();
        $branchNames = $branches->pluck('name', 'id')->all();

        $query = TillUser::query()
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($role !== null, fn ($q) => $q->where('role_id', $role))
            ->when($branch !== null, fn ($q) => $q->whereIn('id', StaffBranch::query()->where('branch_id', $branch)->select('till_user_id')));
        $page = TableQuery::from($request)->searchable(['name'])->sortable(['name', 'updated_at'])->defaultSort('name')->paginate($query);
        $shops = StaffBranch::query()->whereIn('till_user_id', array_map(fn (TillUser $u) => $u->id, $page['data']))->get(['till_user_id', 'branch_id'])->groupBy('till_user_id');

        $page['data'] = array_map(fn (TillUser $u) => [
            'id' => $u->id, 'name' => $u->name, 'role' => $roleNames[$u->role_id] ?? null, 'isActive' => (bool) $u->is_active,
            'hasPin' => ($u->pin_hash ?? '') !== '', 'hasFob' => ($u->rfid ?? '') !== '',
            'branches' => array_values(array_filter(array_map(fn ($b) => $branchNames[$b->branch_id] ?? null, ($shops[$u->id] ?? collect())->all()))),
            'ratePerHour' => $u->rate_per_hour, 'updatedAt' => $u->updated_at?->toIso8601ZuluString(),
        ], $page['data']);

        return [
            'staff' => $page,
            'filters' => ['status' => $status, 'role' => $role, 'branch' => $branch],
            'counts' => ['all' => TillUser::query()->count(), 'active' => TillUser::query()->where('is_active', true)->count()],
            'options' => [
                'roles' => $roles->map(fn (TillRole $r) => ['value' => $r->id, 'label' => $r->name])->values()->all(),
                'branches' => $branches->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            ],
            'canEdit' => self::canEdit(),
        ];
    }

    /** @return array<string, mixed> */
    public static function form(?TillUser $u): array
    {
        $roles = TillRole::query()->orderByDesc('level')->orderBy('name')->get(['id', 'name', 'level', 'is_system']);

        return [
            'member' => $u === null ? null : [
                'id' => $u->id, 'name' => $u->name, 'role_id' => $u->role_id, 'is_active' => (bool) $u->is_active,
                'rate_per_hour' => (string) ($u->rate_per_hour ?? '0.00'), 'max_shift_hours' => rtrim(rtrim((string) ($u->max_shift_hours ?? '0'), '0'), '.') ?: '0',
                'is_service_staff' => (bool) $u->is_service_staff, 'allow_commission' => (bool) $u->allow_commission,
                'is_personal_licence_holder' => (bool) $u->is_personal_licence_holder, 'big_text_mode' => (bool) $u->big_text_mode,
                'simple_mode_override' => $u->simple_mode_override === null ? 'role' : ($u->simple_mode_override ? 'on' : 'off'),
                'branch_ids' => StaffBranch::query()->where('till_user_id', $u->id)->pluck('branch_id')->all(),
                'hasPin' => ($u->pin_hash ?? '') !== '', 'hasFob' => ($u->rfid ?? '') !== '',
                'updatedAt' => $u->updated_at?->toIso8601ZuluString(),
            ],
            'options' => [
                'roles' => $roles->map(fn (TillRole $r) => ['value' => $r->id, 'label' => $r->name, 'isOwner' => self::isOwner($r)])->values()->all(),
                'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            ],
            'canEdit' => self::canEdit(),
        ];
    }

    /** @return array<string, mixed> */
    public static function roles(Request $request): array
    {
        $roles = TillRole::query()->orderByDesc('level')->orderBy('name')->get(['id', 'name', 'level', 'is_system']);
        $grants = TillRolePermission::query()->get(['role_id', 'permission_key'])->groupBy('role_id');
        $staff = TillUser::query()->where('is_active', true)->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');
        $chosen = $roles->firstWhere('id', $request->query('role')) ?? $roles->first();
        $selected = $chosen instanceof TillRole ? $chosen->id : null;

        return [
            'roles' => $roles->map(fn (TillRole $r) => [
                'id' => $r->id, 'name' => $r->name, 'level' => (int) $r->level, 'isSystem' => (bool) $r->is_system, 'isOwner' => self::isOwner($r),
                'staffCount' => (int) ($staff[$r->id] ?? 0),
                'granted' => ($grants[$r->id] ?? collect())->pluck('permission_key')->sort()->values()->all(),
            ])->values()->all(),
            'selected' => $selected,
            'groups' => app(TillPermissionCatalogue::class)->groups(),
            'canEdit' => self::canEdit(),
        ];
    }

    private static function isOwner(TillRole $role): bool
    {
        return (bool) $role->is_system && strcasecmp($role->name, 'Owner') === 0;
    }

    private static function canEdit(): bool
    {
        return app(CurrentCompany::class)->restrictedBranchId() === null;
    }
}
