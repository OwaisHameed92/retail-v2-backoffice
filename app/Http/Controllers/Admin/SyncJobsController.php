<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Operations\Queries\SyncJobsPage;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin "Sync & jobs" (owner and support, audit.view): scheduler, queue backlog, scheduled task runs, failed jobs,
 * sync problems and recent till traffic, so staff can tell the background work is healthy without the server.
 */
class SyncJobsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/sync-jobs/index', SyncJobsPage::props($request, CarbonImmutable::now('UTC')));
    }
}
