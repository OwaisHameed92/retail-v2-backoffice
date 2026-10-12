<?php

namespace App\Http\Controllers\Api;

use App\Domain\Partners\Actions\CancelTenantRequest;
use App\Domain\Partners\Actions\SubmitTenantRequest;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Support\PartnerApiErrors;
use App\Domain\Partners\Support\TenantRequestView;
use App\Domain\Plans\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePartnerKey;
use App\Http\Requests\Partner\PutTenantRequestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner onboarding API v1 (docs/partner-api.md), behind AuthenticatePartnerKey. A partner only ever sees its own
 * requests: another partner's externalId is 404, like an unknown one.
 */
class PartnerTenantRequestController extends Controller
{
    public function upsert(PutTenantRequestRequest $request, string $externalId, SubmitTenantRequest $submit): JsonResponse
    {
        [$tenantRequest, $outcome] = $submit->handle(self::partner($request), $externalId, $request->toInput());

        return response()->json(TenantRequestView::of($tenantRequest), $outcome === 'created' ? 201 : 200);
    }

    public function show(Request $request, string $externalId): JsonResponse
    {
        return response()->json(TenantRequestView::of($this->find($request, $externalId)));
    }

    public function cancel(Request $request, string $externalId, CancelTenantRequest $cancel): JsonResponse
    {
        $tenantRequest = $this->find($request, $externalId);
        $reason = $request->input('reason');

        if ($reason !== null && (! is_string($reason) || mb_strlen($reason) > 500)) {
            throw PartnerApiErrors::invalid(['reason' => ['The reason must be text of up to 500 characters.']]);
        }

        return response()->json(TenantRequestView::of($cancel->handle($tenantRequest, $reason)));
    }

    public function plans(): JsonResponse
    {
        return response()->json(Plan::query()->where('is_active', true)->ordered()->get()->map(fn (Plan $plan) => [
            'code' => $plan->code,
            'name' => $plan->name,
            'setupFee' => $plan->setup_fee,
            'setupFeeMode' => $plan->setup_fee_mode->value,
            'monthlyPerTill' => $plan->price_monthly,
        ])->values());
    }

    private static function partner(Request $request): Partner
    {
        return AuthenticatePartnerKey::key($request)->partner ?? throw PartnerApiErrors::keyRevoked();
    }

    private function find(Request $request, string $externalId): PartnerTenantRequest
    {
        return PartnerTenantRequest::query()->where('partner_id', self::partner($request)->id)->where('external_id', $externalId)->first()
            ?? throw PartnerApiErrors::notFound();
    }
}
