<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Tenancy\Actions\SwitchCurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Concerns\HandlesTwoFactorSignIn;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Portal two-factor sign-in: optional per user (Settings → Security) unless their business requires it.
 */
class TwoFactorController extends Controller
{
    use HandlesTwoFactorSignIn;

    protected function area(): TwoFactorArea
    {
        return TwoFactorArea::Web;
    }

    protected function auditCompanyId(Request $request): ?string
    {
        return $this->company($request)?->id;
    }

    protected function setupContext(Request $request): array
    {
        $company = $this->company($request);
        $required = $company !== null && $company->require_two_factor;

        return [
            'required' => $required,
            'requiredBy' => $required ? $company->name : null,
            'continueUrl' => $request->query('from') === 'settings' ? route('security.edit') : $this->continueUrl($request),
            'cancelUrl' => $required ? null : route('security.edit'),
        ];
    }

    /** The business chosen in this session, if the user still belongs to it. */
    private function company(Request $request): ?Company
    {
        $id = $request->session()->get(SwitchCurrentCompany::SESSION_KEY);
        $user = $request->user();

        if (! is_string($id) || ! $user instanceof User) {
            return null;
        }

        return $user->companies()->whereKey($id)->first();
    }
}
