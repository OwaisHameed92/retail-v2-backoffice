<?php

namespace App\Domain\Shops\Actions;

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Mail\Data\TillRequestData;
use App\Domain\Mail\Mailables\AdminTillRequestMail;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shops\Data\ShopRequest;
use App\Domain\Shops\Enums\ShopRequestKind;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Ask for more tills / another shop" (module 4.7, contract §18.4 item 5). The business cannot raise its own limits;
 * this tells Switch & Save. The request is an admin licence alert (`tillsRequested`) on one of the business's live
 * licences (the shop's own for more tills), so it shows on the admin dashboard's "Needs attention", the tenant and
 * licence pages, and staff mark it resolved when done. One open request per kind and shop: asking again counts up
 * the open one. Staff also get AdminTillRequestMail. Audited as `shops.request_sent`. Nothing is issued here.
 */
class SendShopRequest
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws AuthorizationException a one-shop user asking for another shop or for someone else's shop
     * @throws ValidationException
     */
    public function handle(ShopRequest $request, User $requester): LicenceAlert
    {
        $company = $this->tenancy->require();
        $restricted = $this->tenancy->restrictedBranchId();
        $branch = $this->branchFor($request, $restricted);

        if ($request->tills < 1 || $request->tills > ShopRequest::MAX_TILLS) {
            throw ValidationException::withMessages(['tills' => 'Ask for between 1 and '.ShopRequest::MAX_TILLS.' tills.']);
        }

        $licence = $this->anchorLicence($branch);
        $now = CarbonImmutable::now();
        $what = $this->describe($request, $branch);
        $fingerprint = hash('sha256', LicenceAlertType::TillsRequested->value.'|'.$request->kind->value.'|'.($branch->id ?? 'new'));
        $details = array_filter([
            'summary' => $what.' · asked by '.$requester->name.($request->message !== null ? ' · “'.mb_substr($request->message, 0, 300).'”' : ''),
            'kind' => $request->kind->value,
            'branchId' => $branch?->id,
            'tills' => $request->tills,
            'newShopName' => $request->newShopName,
            'message' => $request->message,
            'requestedBy' => $requester->name,
            'requestedByEmail' => $requester->email,
            'phone' => $request->phone,
        ], fn ($value) => $value !== null);

        $alert = DB::transaction(function () use ($company, $licence, $fingerprint, $details, $now, $request, $branch) {
            $open = LicenceAlert::query()
                ->where('type', LicenceAlertType::TillsRequested->value)
                ->where('fingerprint', $fingerprint)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                $open->forceFill(['count' => $open->count + 1, 'last_seen_at' => $now, 'details' => $details])->save();
            } else {
                $open = LicenceAlert::query()->create([
                    'company_id' => $company->id,
                    'licence_id' => $licence->id,
                    'type' => LicenceAlertType::TillsRequested,
                    'fingerprint' => $fingerprint,
                    'details' => $details,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'count' => 1,
                ]);
            }

            $this->audit->handle('shops.request_sent', $open, null, [
                'kind' => $request->kind->value,
                'tills' => $request->tills,
                'branch_id' => $branch?->id,
                'new_shop_name' => $request->newShopName,
            ], ['count' => $open->count], companyId: $company->id);

            return $open;
        });

        Mail::queue(new AdminTillRequestMail(new TillRequestData(
            businessName: $company->name,
            companyId: $company->id,
            kind: $request->kind->label(),
            what: $what,
            requestedBy: $requester->name,
            email: $requester->email,
            phone: $request->phone,
            receivedAt: $now,
            message: $request->message,
            count: $alert->count,
        )));

        return $alert;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function branchFor(ShopRequest $request, ?string $restricted): ?Branch
    {
        if ($request->kind === ShopRequestKind::NewShop) {
            if ($restricted !== null) {
                throw new AuthorizationException('Only the owner or a manager of every shop can ask for another shop.');
            }
            if ($request->newShopName === null) {
                throw ValidationException::withMessages(['new_shop_name' => 'Tell us where the new shop is.']);
            }

            return null;
        }

        if ($restricted !== null && $request->branchId !== $restricted) {
            throw new AuthorizationException('You can only ask for tills for your own shop.');
        }

        // Company scope: another business's shop is simply not found.
        $branch = $request->branchId === null ? null : Branch::query()->active()->find($request->branchId);

        return $branch ?? throw ValidationException::withMessages(['branch_id' => 'Choose the shop that needs more tills.']);
    }

    /** The licence the alert hangs on: one of the shop's own tills, else the business's oldest live licence. */
    private function anchorLicence(?Branch $branch): Licence
    {
        $live = fn () => Licence::query()->live()->orderBy('created_at')->orderBy('id');

        $licence = ($branch !== null ? $live()->where('branch_id', $branch->id)->first() : null) ?? $live()->first();

        return $licence ?? throw ValidationException::withMessages([
            'kind' => 'We could not send this online because none of your tills has a licence yet. Please call or email Switch & Save.',
        ]);
    }

    /** "2 more tills for Leeds (LDS)" / "A new shop in Harrogate with 1 till" */
    private function describe(ShopRequest $request, ?Branch $branch): string
    {
        $tills = $request->tills.' '.($request->tills === 1 ? 'till' : 'tills');

        return $branch !== null
            ? $request->tills.' more '.($request->tills === 1 ? 'till' : 'tills')." for {$branch->name} ({$branch->code})"
            : "A new shop, {$request->newShopName}, with {$tills}";
    }
}
