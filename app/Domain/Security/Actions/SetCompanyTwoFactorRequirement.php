<?php

namespace App\Domain\Security\Actions;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The company owner requires (or stops requiring) two-factor sign-in for everyone in the business. Users without it
 * are sent to set it up at their next request. The person turning it on must already use it (so they are not
 * locked out of the page they are on). Audited as `company.two_factor_requirement_changed`.
 */
final class SetCompanyTwoFactorRequirement
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, bool $required, ?TwoFactorUser $actor = null): void
    {
        if ($required && $actor !== null && ! $actor->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['requireTwoFactor' => 'Turn on two-factor sign-in for your own account first.']);
        }

        $before = (bool) $company->require_two_factor;

        if ($before === $required) {
            return;
        }

        $company->forceFill(['require_two_factor' => $required])->save();

        $this->audit->handle('company.two_factor_requirement_changed', $company, ['requireTwoFactor' => $before], ['requireTwoFactor' => $required], companyId: $company->id);
    }
}
