<?php

namespace App\Http\Controllers\App;

use App\Domain\Calendar\Actions\SaveOpeningHours;
use App\Domain\Calendar\Data\CalendarFilters;
use App\Domain\Calendar\Queries\EventComparison;
use App\Domain\Calendar\Queries\OpeningHoursPage;
use App\Domain\Calendar\Queries\SeasonalEventList;
use App\Domain\Calendar\Queries\SpecialDays;
use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\SeasonalEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Calendar\CalendarRequest;
use App\Http\Requests\App\Calendar\OpeningHoursRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The calendar of the tenant portal (module 5.9, `company.can:calendar.manage`): each shop's weekly opening hours
 * (kept on the portal, sent to the tills as the `shop.trading_hours` setting and used by Till health), the tills'
 * special days and seasonal events (branch-owned: listed only) and an event's sales against last year's. A one-shop
 * user sees and changes their own shop only.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function hours(CalendarRequest $request): Response
    {
        return Inertia::render('app/calendar/hours', OpeningHoursPage::for($request->filters()));
    }

    public function updateHours(OpeningHoursRequest $request, string $branch, SaveOpeningHours $save): RedirectResponse
    {
        $company = $this->tenancy->require();
        $shops = $request->boolean('everyShop') ? Branch::query()->orderBy('name')->get() : collect([Branch::query()->findOrFail($branch)]);
        $changed = 0;

        foreach ($shops as $shop) {
            $changed += $save->handle($company, $shop, $request->week()) ? 1 : 0;
        }

        $message = match (true) {
            $changed === 0 => 'Nothing changed.',
            $request->boolean('clear') => 'Opening hours removed. The tills get the change in their next sync.',
            $shops->count() > 1 => "Opening hours saved for {$changed} shops. The tills get them in their next sync.",
            default => 'Opening hours saved. The tills get them in their next sync.',
        };

        return back()->with('success', $message);
    }

    public function specialDays(CalendarRequest $request): Response
    {
        return $this->page('app/calendar/special-days', $request->filters(), SpecialDays::for($request->filters()));
    }

    public function events(CalendarRequest $request): Response
    {
        return $this->page('app/calendar/events', $request->filters(), SeasonalEventList::for($request->filters()));
    }

    public function event(CalendarRequest $request, string $event): Response
    {
        $filters = $request->filters();
        $model = SeasonalEvent::query()->findOrFail($event);
        abort_if($filters->shopLocked && $model->branch_id !== $filters->shop, 404);

        return Inertia::render('app/calendar/event', [
            ...EventComparison::for($model, $request->everyShop(), $filters->today),
            'canCompareEveryShop' => ! $filters->shopLocked && Branch::query()->count() > 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(string $component, CalendarFilters $filters, array $props): Response
    {
        $shops = CashLookup::options(new CashFilters($filters->today, $filters->today, $filters->shop, shopLocked: $filters->shopLocked))['shops'];

        return Inertia::render($component, [...$props, 'filters' => $filters->toArray(), 'shops' => $shops]);
    }
}
