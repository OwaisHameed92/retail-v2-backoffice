<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveStoreClosure;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveStoreClosureRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * Closed days and special hours per shop (audit OSA-13, portal → Online store → Collection shops, `store.manage`):
 * add or change one, or remove it. A one-shop user changes their own shop only.
 */
class OnlineStoreClosureController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function store(SaveStoreClosureRequest $request, string $branch, SaveStoreClosure $save): RedirectResponse
    {
        $closure = $save->handle($this->tenancy->require(), $branch, $request->closure());
        $day = CarbonImmutable::parse($closure->date)->format('l j F');

        return back()->with('success', $closure->opens_at === null
            ? "Closed on {$day}: no collections or deliveries that day."
            : "Special hours on {$day}: {$closure->opens_at} to {$closure->closes_at}.");
    }

    public function destroy(SaveStoreClosureRequest $request, string $branch, string $closure, SaveStoreClosure $save): RedirectResponse
    {
        $save->remove($this->tenancy->require(), $branch, $closure);

        return back()->with('success', 'Closed day removed. The usual hours apply.');
    }
}
