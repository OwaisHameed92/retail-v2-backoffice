<?php

namespace App\Domain\Security\Actions;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\RecoveryCodes;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Replaces all recovery codes with ten new ones (the old ones stop working) and returns them: shown once.
 * The password is checked by the form request. Audited as `two_factor.recovery_codes_regenerated`.
 */
final class RegenerateRecoveryCodes
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function handle(TwoFactorArea $area, TwoFactorUser&Model $user, ?string $companyId = null): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['password' => 'Turn on two-factor sign-in first.']);
        }

        $codes = RecoveryCodes::generate();
        $user->forceFill(['two_factor_recovery_codes' => RecoveryCodes::hashAll($codes)])->save();

        $this->audit->handle('two_factor.recovery_codes_regenerated', $user, null, null, ['guard' => $area->value], actor: $user, companyId: $companyId);

        return $codes;
    }
}
