<?php

namespace App\Http\Controllers\App;

use App\Domain\ShopSettings\Actions\SaveShopSettings;
use App\Domain\ShopSettings\Queries\ShopSettingsPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ShopSettings\ShopSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Till settings page (module 4.9, `company.can:settings.manage`): receipt text, opening hours, cash-up rules and
 * more, for every shop or one shop. A one-shop user sees and changes only their own shop.
 */
class ShopSettingsController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        $restricted = $this->tenancy->restrictedBranchId();
        $id = $request->query('shop');
        $id = is_string($id) && $id !== '' ? $id : $restricted;

        abort_if($restricted !== null && $id !== $restricted, 403);

        // Company scope: another business's shop is simply not found.
        $shop = $id === null ? null : Branch::query()->active()->findOrFail($id);

        return Inertia::render('app/settings/index', ShopSettingsPage::for($this->tenancy->require(), $shop, $restricted));
    }

    public function update(ShopSettingsRequest $request, SaveShopSettings $save): RedirectResponse
    {
        $shop = $request->shop();
        $changed = $save->handle($this->tenancy->require(), $shop, $request->values());
        $where = $shop === null ? 'every shop' : $shop->name;

        return back()->with('success', $changed === []
            ? 'Nothing changed.'
            : 'Saved for '.$where.'. The tills use '.(count($changed) === 1 ? 'it' : 'them').' within about 30 seconds of their next sync.');
    }
}
