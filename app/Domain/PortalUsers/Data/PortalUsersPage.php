<?php

namespace App\Domain\PortalUsers\Data;

use App\Domain\PortalUsers\Models\CompanyInvitation;
use App\Domain\PortalUsers\Support\RoleMatrix;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Props of the tenant portal's Portal users page (module 4.1): members, open invitations, the shops a user can be
 * limited to and the role matrix. Runs under the current company (invitations and branches are tenant-scoped; the
 * membership query filters on the company explicitly).
 */
final class PortalUsersPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, User $viewer): array
    {
        $branches = Branch::query()->withTrashed()->get(['id', 'name', 'code', 'is_active', 'deleted_at']);
        $names = $branches->pluck('name', 'id');

        $members = DB::table('company_user')
            ->join('users', 'users.id', '=', 'company_user.user_id')
            ->where('company_user.company_id', $company->getKey())
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'company_user.role', 'company_user.branch_id', 'company_user.is_active', 'company_user.created_at'])
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role' => (string) $row->role,
                'roleLabel' => CompanyRole::tryFrom((string) $row->role)?->label() ?? (string) $row->role,
                'branchId' => $row->branch_id,
                'branchName' => $row->branch_id !== null ? ($names[$row->branch_id] ?? 'Removed shop') : null,
                'isActive' => (bool) $row->is_active,
                'isYou' => (int) $row->id === (int) $viewer->getKey(),
                'joinedAt' => $row->created_at !== null ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ])
            ->values();

        $invitations = CompanyInvitation::query()->open()->with('inviter:id,name')->latest()->get()
            ->map(fn (CompanyInvitation $invitation) => [
                'id' => $invitation->id,
                'name' => $invitation->name,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'roleLabel' => $invitation->role->label(),
                'branchName' => $invitation->branch_id !== null ? ($names[$invitation->branch_id] ?? 'Removed shop') : null,
                'status' => $invitation->status()->value,
                'invitedBy' => $invitation->inviter?->name,
                'sentAt' => ($invitation->last_sent_at ?? $invitation->created_at)?->toIso8601String(),
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'sendCount' => $invitation->send_count,
            ])
            ->values();

        $active = $members->where('isActive', true);

        return [
            'members' => $members->all(),
            'invitations' => $invitations->all(),
            'stats' => [
                'active' => $active->count(),
                'owners' => $active->where('role', CompanyRole::Owner->value)->count(),
                'oneShop' => $active->whereNotNull('branchId')->count(),
                'deactivated' => $members->count() - $active->count(),
                'pendingInvitations' => $invitations->where('status', 'pending')->count(),
            ],
            'roles' => RoleMatrix::roles(),
            'matrix' => RoleMatrix::rows(),
            'branches' => $branches->filter(fn (Branch $branch) => $branch->is_active && $branch->deleted_at === null)
                ->sortBy('name')
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code])
                ->values()
                ->all(),
            'validDays' => CompanyInvitation::VALID_DAYS,
        ];
    }
}
