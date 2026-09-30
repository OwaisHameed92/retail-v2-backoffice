<?php

namespace App\Http\Controllers\App;

use App\Domain\Shops\Actions\SendShopRequest;
use App\Domain\Shops\Actions\UpdateBusinessDetails;
use App\Domain\Shops\Actions\UpdateShopDetails;
use App\Domain\Shops\Enums\ShopRequestKind;
use App\Domain\Shops\Queries\BusinessPage;
use App\Domain\Shops\Queries\ShopDetail;
use App\Domain\Shops\Queries\ShopsOverview;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Shops\BusinessDetailsRequest;
use App\Http\Requests\App\Shops\ShopDetailsRequest;
use App\Http\Requests\App\Shops\ShopRequestRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shops and tills on the tenant portal (module 4.7). Read: `shops.view`; shop edits and requests: `shops.manage`;
 * business details: `business.manage`. Shops are found through the company scope (another business's shop is a 404);
 * a one-shop user reaches their own shop only (403).
 */
class ShopController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(): Response
    {
        return Inertia::render('app/shops/index', ShopsOverview::for($this->tenancy, CarbonImmutable::now()));
    }

    public function show(string $branch): Response
    {
        return Inertia::render('app/shops/show', ShopDetail::for($this->tenancy, $this->find($branch), CarbonImmutable::now()));
    }

    public function update(ShopDetailsRequest $request, string $branch, UpdateShopDetails $update): RedirectResponse
    {
        $shop = $update->handle($this->find($branch), $request->details());

        return back()->with('success', "{$shop->name} saved. The shop's tills get the change at their next sync.");
    }

    public function business(): Response
    {
        return Inertia::render('app/shops/business', BusinessPage::for($this->tenancy));
    }

    public function updateBusiness(BusinessDetailsRequest $request, UpdateBusinessDetails $update): RedirectResponse
    {
        $update->handle($this->tenancy->require(), $request->details());

        return back()->with('success', 'Business details saved. Every till gets them at its next sync.');
    }

    public function request(ShopRequestRequest $request, SendShopRequest $send): RedirectResponse
    {
        $details = $request->shopRequest();
        /** @var User $user */
        $user = $request->user();
        $alert = $send->handle($details, $user);

        $what = $details->kind === ShopRequestKind::NewShop ? 'another shop' : 'more tills';

        return back()->with('success', $alert->count > 1
            ? "We already had your request for {$what}; we have reminded the team. They will be in touch soon."
            : "Request sent. The Switch & Save team will be in touch about {$what}, usually within one working day.");
    }

    private function find(string $id): Branch
    {
        $branch = Branch::query()->findOrFail($id);
        $restricted = $this->tenancy->restrictedBranchId();

        abort_if($restricted !== null && $restricted !== $branch->id, 403, 'You can only see your own shop.');

        return $branch;
    }
}
