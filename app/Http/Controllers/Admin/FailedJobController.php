<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Operations\Actions\DeleteFailedJobs;
use App\Domain\Operations\Actions\RetryFailedJobs;
use App\Domain\Operations\Queries\FailedJobDetail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FailedJobsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Admin "Sync & jobs" → Failed jobs (audit.view): the detail dialog (JSON, secrets removed), retry (`queue:retry`)
 * and delete (`queue:forget`). Retry and delete are audited by their actions.
 */
class FailedJobController extends Controller
{
    public function show(string $uuid): JsonResponse
    {
        $detail = FailedJobDetail::for($uuid);
        abort_if($detail === null, 404);

        return response()->json($detail);
    }

    public function retry(FailedJobsRequest $request, RetryFailedJobs $retry): RedirectResponse
    {
        $result = $retry->handle($request->uuids());
        $sent = $result['retried'] === 1 ? '1 job sent back to the queue.' : "{$result['retried']} jobs sent back to the queue.";

        if ($result['failed'] > 0) {
            $kept = $result['failed'] === 1 ? '1 job could not be retried and is still in the list.' : "{$result['failed']} jobs could not be retried and are still in the list.";

            return back()->with('error', $result['retried'] > 0 ? "{$sent} {$kept}" : $kept);
        }

        if ($result['retried'] === 0) {
            return back()->with('error', 'These jobs are no longer in the failed list.');
        }

        return back()->with('success', $sent);
    }

    public function destroy(FailedJobsRequest $request, DeleteFailedJobs $delete): RedirectResponse
    {
        $result = $delete->handle($request->uuids());

        if ($result['deleted'] === 0) {
            return back()->with('error', 'These jobs are no longer in the failed list.');
        }

        return back()->with('success', $result['deleted'] === 1 ? 'Failed job deleted.' : "{$result['deleted']} failed jobs deleted.");
    }
}
