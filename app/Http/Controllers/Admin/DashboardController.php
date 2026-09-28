<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Models\Admin;
use App\Domain\Admin\Queries\AdminDashboard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The super admin dashboard (module 1.9). `revenue` reloads on its own when the range changes.
 */
class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, AdminDashboard $dashboard): Response
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $range = $request->range();

        return Inertia::render('admin/dashboard', [
            'range' => $range->value,
            'dashboard' => fn () => $dashboard->forAdmin($admin),
            'revenue' => fn () => $dashboard->revenueFor($admin, $range),
        ]);
    }
}
