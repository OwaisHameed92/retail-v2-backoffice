<?php

namespace App\Http\Controllers\App;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Anomalies\Actions\ChangeAnomalyStatus;
use App\Domain\Anomalies\Actions\ExplainAnomaly;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Queries\AnomalyDetail;
use App\Domain\Anomalies\Queries\AnomalyList;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Anomalies\AnomalyFilterRequest;
use App\Http\Requests\App\Anomalies\ChangeAnomalyStatusRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unusual activity (module 6.6): `company.can:reports.view` to see findings (staff-level ones: owners and managers;
 * a one-shop user: their shop), owners and managers change their status, and `ai.use` asks for a plain-words
 * explanation. A finding the user may not see is a 404.
 */
class AnomalyController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(AnomalyFilterRequest $request): Response
    {
        return Inertia::render('app/anomalies/index', AnomalyList::for($request, $request->filters(), $this->tenancy->role(), $this->tenancy->restrictedBranchId()));
    }

    public function show(string $anomaly): Response
    {
        return Inertia::render('app/anomalies/show', AnomalyDetail::for(AnomalyVisibility::find($anomaly, $this->tenancy), $this->tenancy));
    }

    public function status(ChangeAnomalyStatusRequest $request, string $anomaly, ChangeAnomalyStatus $change): RedirectResponse
    {
        $row = AnomalyVisibility::find($anomaly, $this->tenancy);
        $status = $request->status();
        $change->handle($row->id, $status, $request->validated('reason'), (int) $request->user()?->getKey());

        return back()->with('success', match ($status) {
            AnomalyStatus::Acknowledged => 'Marked as acknowledged.',
            AnomalyStatus::Dismissed => 'Dismissed. The reason is kept in the history.',
            AnomalyStatus::New => 'Reopened.',
        });
    }

    public function explain(Request $request, string $anomaly, ExplainAnomaly $explain): JsonResponse
    {
        $row = AnomalyVisibility::find($anomaly, $this->tenancy);

        try {
            $context = AiContext::forUser($request->user(), $this->tenancy->require(), AiFeature::AnomalyAlerts);

            return response()->json($explain->handle($context, $row));
        } catch (AiAccessDenied) {
            abort(403);
        } catch (AiUnavailable $e) {
            return response()->json(['text' => null, 'message' => $e->getMessage()]);
        }
    }
}
