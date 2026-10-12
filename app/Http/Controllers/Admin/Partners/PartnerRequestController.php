<?php

namespace App\Http\Controllers\Admin\Partners;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\OnboardingBilling;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Data\LicenceFormData;
use App\Domain\Partners\Actions\ApproveTenantRequest;
use App\Domain\Partners\Actions\RejectTenantRequest;
use App\Domain\Partners\Data\PartnerRequestDetail;
use App\Domain\Partners\Data\ReviewFormDefaults;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Queries\PartnerRequestList;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\Nation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Partners\ApprovePartnerRequestRequest;
use App\Http\Requests\Admin\Partners\RejectPartnerRequestRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Partner requests (tenants.manage): deals sent by partners through the partner API, reviewed, then approved
 * into a business through the normal onboarding or rejected with a reason.
 */
class PartnerRequestController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/partner-requests/index', PartnerRequestList::for($request));
    }

    public function show(Request $request, PartnerTenantRequest $partnerRequest): Response
    {
        $admin = $request->user('admin');

        return Inertia::render('admin/partner-requests/show', PartnerRequestDetail::for($partnerRequest) + [
            // The review form (pending requests only): the wizard's fields, pre-filled from the partner's body.
            'form' => $partnerRequest->isPending() ? ReviewFormDefaults::for($partnerRequest) : null,
            'options' => [
                'nations' => Nation::options(),
                'maxTills' => NewTenant::MAX_TILLS,
                'maxShops' => Lead::MAX_SHOPS,
                'plans' => LicenceData::planOptions(),
                'licenceOptions' => LicenceFormData::options(),
                'planDefaults' => LicenceFormData::planDefaults(),
                'billing' => OnboardingBilling::options($admin),
            ],
            'can' => [
                'manage' => $admin?->hasAbility(AdminRole::TENANTS_MANAGE) ?? false,
                'viewTenant' => $admin?->hasAbility(AdminRole::TENANTS_VIEW) ?? false,
                'billing' => $admin?->hasAbility(AdminRole::BILLING_MANAGE) ?? false,
            ],
        ]);
    }

    public function approve(ApprovePartnerRequestRequest $request, PartnerTenantRequest $partnerRequest, ApproveTenantRequest $approve): RedirectResponse
    {
        $company = $approve->handle($partnerRequest, $request->approval(), $request->user('admin')?->getKey());

        return redirect()->route('admin.tenants.show', $company)
            ->with('success', "{$company->name} is set up from the partner's request. The partner is told it is approved.");
    }

    public function reject(RejectPartnerRequestRequest $request, PartnerTenantRequest $partnerRequest, RejectTenantRequest $reject): RedirectResponse
    {
        $reject->handle($partnerRequest, (string) $request->validated('reason'), $request->user('admin')?->getKey());

        return redirect()->route('admin.partner-requests.show', $partnerRequest)
            ->with('success', 'Request rejected. The partner sees your reason.');
    }
}
