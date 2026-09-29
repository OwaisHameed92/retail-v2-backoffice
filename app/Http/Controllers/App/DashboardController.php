<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillHealth\Queries\ShopsStatus;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $current, ResolveCurrentBranch $branch): Response
    {
        return Inertia::render('app/dashboard', [
            // Module 2.7: shops and tills status (read only), for the branch picked in the switcher or all.
            'status' => $current->can('dashboard.view')
                ? ShopsStatus::for($current->require(), $branch->handle($request->session())?->id, CarbonImmutable::now())
                : null,
        ]);
    }
}
