<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Data\LeadActivity;
use App\Domain\Licensing\Data\LicenceActivity;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns a company's audit log entries into readable activity rows for the admin tenant page.
 */
final class TenantActivity
{
    /** @var array<string, string> */
    private array $branchNames = [];

    /** @var array<string, string> */
    private array $registerNames = [];

    /** @var array<string, string> */
    private array $actorNames = [];

    /** @var array<string, string> licence id => "Till 2 (02) at Leeds" */
    private array $licenceTills = [];

    /**
     * @param  Collection<int, AuditLog>  $entries
     */
    public function __construct(Collection $entries)
    {
        $ids = fn (string $type) => $entries->where('subject_type', $type)->pluck('subject_id')->filter()->unique()->values()->all();

        $licences = Licence::withoutCompanyScope()->withTrashed()->whereIn('id', $ids((new Licence)->getMorphClass()))->get(['id', 'register_id']);
        $registerIds = array_merge($ids((new Register)->getMorphClass()), $licences->pluck('register_id')->all());
        $registers = Register::withoutCompanyScope()->withTrashed()->whereIn('id', array_unique($registerIds))->get();
        $branchIds = array_merge($ids((new Branch)->getMorphClass()), $registers->pluck('branch_id')->all());
        $branches = Branch::withoutCompanyScope()->withTrashed()->whereIn('id', array_unique($branchIds))->pluck('name', 'id');

        $this->branchNames = $branches->map(fn ($name) => (string) $name)->all();

        foreach ($registers as $register) {
            $branch = $this->branchNames[$register->branch_id] ?? 'a branch';
            $this->registerNames[$register->id] = "{$register->name} ({$register->code}) at {$branch}";
        }

        foreach ($licences as $licence) {
            $this->licenceTills[$licence->id] = $this->registerNames[$licence->register_id] ?? 'a till';
        }

        $adminIds = $entries->where('actor_type', (new Admin)->getMorphClass())->pluck('actor_id')->filter()->unique()->all();
        foreach (Admin::query()->whereIn('id', $adminIds)->get(['id', 'name']) as $admin) {
            $this->actorNames['admin:'.$admin->id] = $admin->name;
        }

        $userIds = $entries->where('actor_type', (new User)->getMorphClass())->pluck('actor_id')->filter()->unique()->all();
        foreach (User::query()->whereIn('id', $userIds)->get(['id', 'name']) as $user) {
            $this->actorNames['user:'.$user->id] = $user->name;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function row(AuditLog $entry): array
    {
        $actorKind = match ($entry->actor_type) {
            (new Admin)->getMorphClass() => 'admin',
            (new User)->getMorphClass() => 'user',
            null => 'system',
            default => 'other',
        };

        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'description' => $this->describe($entry),
            'actorKind' => $actorKind,
            'actorName' => match ($actorKind) {
                'admin' => ($this->actorNames['admin:'.$entry->actor_id] ?? 'Former admin').' (Switch & Save)',
                'user' => $this->actorNames['user:'.$entry->actor_id] ?? 'Former user',
                default => 'System',
            },
            'createdAt' => $entry->created_at?->toIso8601String(),
        ];
    }

    private function describe(AuditLog $entry): string
    {
        $meta = $entry->meta ?? [];
        $before = $entry->before ?? [];
        $after = $entry->after ?? [];
        $branch = $this->branchNames[(string) $entry->subject_id] ?? 'a branch';
        $register = $this->registerNames[(string) $entry->subject_id] ?? 'a till';
        $email = (string) ($meta['email'] ?? $meta['user_email'] ?? 'a user');
        $role = fn (mixed $value) => CompanyRole::tryFrom((string) $value)?->label() ?? (string) $value;

        if (str_starts_with($entry->action, 'licence.')) {
            return LicenceActivity::describe($entry, $this->licenceTills[(string) $entry->subject_id] ?? 'a till');
        }

        if (str_starts_with($entry->action, 'lead.')) {
            return LeadActivity::describe($entry);
        }

        return match ($entry->action) {
            'company.created' => 'Created the business',
            'company.plan_changed' => 'Changed the plan for new tills to '.($meta['to_plan_name'] ?? $after['plan'] ?? 'another plan'),
            'company.updated' => 'Updated business details: '.$this->fields($after),
            'company.activated' => 'Marked the business as active',
            'company.suspended' => 'Suspended the business: '.($meta['reason'] ?? ''),
            'company.unsuspended' => 'Lifted the suspension',
            'company.cancelled' => 'Cancelled the business: '.($meta['reason'] ?? ''),
            'branch.created' => "Added branch {$branch}",
            'branch.updated' => "Updated branch {$branch}: ".$this->fields($after),
            'branch.deactivated' => "Deactivated branch {$branch}",
            'branch.reactivated' => "Reactivated branch {$branch}",
            'register.created' => "Added till {$register}",
            'register.updated' => "Updated till {$register}: ".$this->fields($after),
            'register.main_till_changed' => "Made {$register} the main till",
            'register.deactivated' => "Deactivated till {$register}",
            'register.reactivated' => "Reactivated till {$register}",
            'company.user_added' => "Added {$email} as ".$role($after['role'] ?? ''),
            'company.user_role_changed' => "Changed {$email} from ".$role($before['role'] ?? '').' to '.$role($after['role'] ?? ''),
            'company.user_removed' => "Removed {$email}",
            'company.user_password_link_sent' => "Sent a set-password email to {$email}",
            'company.impersonation_started' => "Logged in to the portal as {$email}",
            'company.impersonation_ended' => 'Returned to admin from the customer portal',
            default => $entry->action,
        };
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function fields(array $after): string
    {
        $names = array_map(fn (string $key) => str_replace('_', ' ', $key), array_keys($after));

        return $names === [] ? 'no changes' : implode(', ', $names);
    }
}
