<?php

namespace App\Domain\Ai;

use App\Domain\Admin\Models\Admin;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who is asking, for which company, for which feature. Every AI call, tool run and proposal carries one.
 *
 * - forUser: a tenant user; the role is read from their active membership and re-read on every tool call and
 *   confirmation (currentRole()), so a role change or removal takes effect immediately.
 * - forSystem: a job for one company (morning summary, alerts). Read tools only; writes always need a person.
 * - forAdmin: an SSPOS staff member (admin tools, module 5.7). Company is optional context.
 */
final class AiContext
{
    private function __construct(
        public readonly AiFeature $feature,
        public readonly ?Company $company,
        public readonly ?User $user,
        public readonly ?Admin $admin,
    ) {}

    /**
     * @throws AiAccessDenied when the user is not an active member of the company
     */
    public static function forUser(User $user, Company $company, AiFeature $feature = AiFeature::Assistant): self
    {
        $context = new self($feature, $company, $user, null);

        if ($context->currentRole() === null) {
            throw AiAccessDenied::notMember();
        }

        return $context;
    }

    public static function forSystem(Company $company, AiFeature $feature): self
    {
        return new self($feature, $company, null, null);
    }

    public static function forAdmin(Admin $admin, AiFeature $feature = AiFeature::AdminAssistant, ?Company $company = null): self
    {
        return new self($feature, $company, null, $admin);
    }

    public function withFeature(AiFeature $feature): self
    {
        return new self($feature, $this->company, $this->user, $this->admin);
    }

    public function isTenantUser(): bool
    {
        return $this->user !== null && $this->company !== null;
    }

    public function isSystem(): bool
    {
        return $this->user === null && $this->admin === null;
    }

    public function isAdmin(): bool
    {
        return $this->admin !== null;
    }

    /** The person behind the call (for audit and ownership), null for system jobs. */
    public function actor(): ?Model
    {
        return $this->user ?? $this->admin;
    }

    /**
     * The user's role in the company, read fresh from the database (null when not an active member).
     */
    public function currentRole(): ?CompanyRole
    {
        if ($this->user === null || $this->company === null) {
            return null;
        }

        $role = CompanyMembership::query()
            ->where('company_id', $this->company->getKey())
            ->where('user_id', $this->user->getKey())
            ->where('is_active', true)
            ->first()
            ?->role;

        return $role instanceof CompanyRole ? $role : null;
    }

    public function userCan(Ability $ability): bool
    {
        return $this->currentRole()?->can($ability) === true;
    }

    public function adminCan(string $ability): bool
    {
        return $this->admin !== null && $this->admin->fresh()?->hasAbility($ability) === true;
    }

    /** Stable owner fields for rows this context creates (conversations, usage, proposals). */
    public function companyId(): ?string
    {
        return $this->company?->getKey();
    }

    public function userId(): ?int
    {
        $id = $this->user?->getKey();

        return $id === null ? null : (int) $id;
    }

    public function adminId(): ?string
    {
        $id = $this->admin?->getKey();

        return $id === null ? null : (string) $id;
    }
}
