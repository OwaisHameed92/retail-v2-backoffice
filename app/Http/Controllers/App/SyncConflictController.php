<?php

namespace App\Http\Controllers\App;

use App\Domain\TillData\Actions\ResolveSyncConflict;
use App\Domain\TillData\Models\TillSyncConflict;
use App\Domain\TillData\Queries\SyncConflictDetail;
use App\Domain\TillData\Queries\SyncConflictList;
use App\Domain\TillData\Sync\Models\SyncConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ResolveSyncConflictRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant portal's sync conflicts screen (module 2.9B): the shop changes the portal kept out, reviewed and settled
 * here (`company.can:sync.manage`: owner and manager), and the tills' own clashes, read only. Every query runs in the
 * current company's scope: another business's conflict is simply not found. A one-shop manager sees only their shop's
 * rows and cannot settle any (settling `useTill` changes what every shop uses; security review M1).
 */
class SyncConflictController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/sync/conflicts', SyncConflictList::for($request));
    }

    public function show(string $conflict): Response
    {
        return Inertia::render('app/sync/conflict', SyncConflictDetail::conflict(SyncConflictList::visible(SyncConflict::query())->findOrFail($conflict)));
    }

    public function resolve(ResolveSyncConflictRequest $request, string $conflict, ResolveSyncConflict $resolve): RedirectResponse
    {
        $model = SyncConflictList::visible(SyncConflict::query())->findOrFail($conflict);
        $resolved = $resolve->handle($model, $request->resolution(), $request->user(), $request->note());

        return redirect()->route('app.sync.conflicts.show', $resolved->id)->with('success', match ($request->resolution()->value) {
            'useTill' => 'The shop\'s version is now the portal\'s and goes to every till at their next sync.',
            'keepPortal' => 'Conflict resolved. The portal\'s version stays.',
            default => 'Conflict acknowledged.',
        });
    }

    public function clash(string $clash): Response
    {
        return Inertia::render('app/sync/clash', SyncConflictDetail::clash(SyncConflictList::visible(TillSyncConflict::query())->findOrFail($clash)));
    }
}
