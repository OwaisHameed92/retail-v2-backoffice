<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveStoreLegal;
use App\Domain\OnlineStore\Queries\StoreLegalPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveStoreLegalRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The online store's "Legal & contact" tab (OS-11, audit OSA-2, `store.manage`): the trader details and the shop's own
 * text for the generated contact, privacy, terms and cookies pages. Store-wide: a one-shop user may look only (403 on
 * save, the same rule as the store's ways to pay).
 */
class OnlineStoreLegalController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function edit(): Response
    {
        return Inertia::render('app/online-store/legal', StoreLegalPage::for($this->tenancy->require(), $this->tenancy->restrictedBranchId() === null));
    }

    public function update(SaveStoreLegalRequest $request, SaveStoreLegal $save): RedirectResponse
    {
        $store = $save->handle($this->tenancy->require(), $request->only(['trading_name', 'legal_name', 'legal_address', 'company_number',
            'vat_number', 'contact_email', 'contact_phone', 'extra']));

        return back()->with('success', $store->is_live ? 'Legal details saved. Customers see them now.' : 'Legal details saved.');
    }
}
