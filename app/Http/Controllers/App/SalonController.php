<?php

namespace App\Http\Controllers\App;

use App\Domain\Salon\Actions\IssueCalendarFeed;
use App\Domain\Salon\Actions\RevokeCalendarFeed;
use App\Domain\Salon\Calendar\FeedLink;
use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Salon\Queries\SalonPage;
use App\Domain\Salon\Queries\SalonStylists;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Salon\CalendarFeedRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant "Salon" page (salon W3b, `company.can:salon.manage`: owner and manager), only for a business with a
 * salon shop while SALON_ONLINE is on (EnsureSalonOnline, else 404): stylists' calendar feeds (on, new address, off) and the online
 * booking requests, read only. The feed address is in the JSON reply to "on" / "new address" only, never a page prop
 * or the session. Another business's feed is simply not found (tenant scope).
 */
class SalonController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request, ResolveCurrentBranch $branch, SalonPage $page): Response
    {
        return Inertia::render('app/salon/index', $page->handle($request, $branch->handle($request->session()), CarbonImmutable::now()));
    }

    public function issueFeed(CalendarFeedRequest $request, IssueCalendarFeed $issue): JsonResponse
    {
        $result = $issue->handle($request->shop(), $request->stylist(), $this->actor($request));

        return response()->json([
            'feed' => SalonStylists::feed($result['feed']),
            'regenerated' => $result['regenerated'],
            ...FeedLink::reply($result['feed'], $result['token']),
        ])->header('Cache-Control', 'no-store');
    }

    public function revokeFeed(Request $request, string $feed, RevokeCalendarFeed $revoke): JsonResponse
    {
        $model = SalonCalendarFeed::query()
            ->when($this->tenancy->restrictedBranchId() !== null, fn ($query) => $query->where('branch_id', $this->tenancy->restrictedBranchId()))
            ->findOrFail($feed);
        $changed = $revoke->handle($model, $this->actor($request));

        return response()->json(['feed' => SalonStylists::feed($model), 'changed' => $changed]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
