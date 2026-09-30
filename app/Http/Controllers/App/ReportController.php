<?php

namespace App\Http\Controllers\App;

use App\Domain\Reporting\Actions\BuildReport;
use App\Domain\Reporting\Dashboard\BusinessContext;
use App\Domain\Reporting\Dashboard\ShopFreshness;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\Reports\ReportCsv;
use App\Domain\Reporting\Reports\ReportGrouping;
use App\Domain\Reporting\Reports\ReportHeading;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ReportRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (module 4.8, `reports.view`): the hub, one report on screen, as CSV, and as a printable page. The shop is
 * the top-bar switcher's (a one-shop user: always theirs); dates, compare, till, grouping and tab are in the URL.
 */
class ReportController extends Controller
{
    public function index(ReportRequest $request, CurrentCompany $current, ResolveCurrentBranch $switcher): Response
    {
        $context = BusinessContext::for($current, $switcher->handle($request->session()), $request->input('till'));
        $options = $request->options($current, $context['branch']['id'] ?? null, $context['till']['id'] ?? null);

        return Inertia::render('app/reports/index', [
            'reports' => ReportKind::options(),
            'filters' => $this->filters($options, null),
            'context' => $context,
        ]);
    }

    public function show(ReportRequest $request, ReportKind $report, CurrentCompany $current, ResolveCurrentBranch $switcher, BuildReport $build): Response
    {
        $context = BusinessContext::for($current, $switcher->handle($request->session()), $request->input('till'));
        $options = $this->withView($report, $request->options($current, $context['branch']['id'] ?? null, $context['till']['id'] ?? null));

        return Inertia::render('app/reports/show', [
            'report' => $this->meta($report),
            'reports' => ReportKind::options(),
            'filters' => $this->filters($options, $report),
            'context' => $context,
            'periods' => TradingPeriod::options(),
            'compares' => TradingCompare::options(),
            'groupings' => ReportGrouping::options(),
            'result' => $build->handle($report, $options)->toArray(),
            'freshness' => $report === ReportKind::Stock || $report === ReportKind::Shifts ? null : ShopFreshness::for($options->scope(), CarbonImmutable::now()),
        ]);
    }

    public function export(ReportRequest $request, ReportKind $report, CurrentCompany $current, ResolveCurrentBranch $switcher, BuildReport $build): StreamedResponse
    {
        $context = BusinessContext::for($current, $switcher->handle($request->session()), $request->input('till'));
        $options = $this->withView($report, $request->options($current, $context['branch']['id'] ?? null, $context['till']['id'] ?? null, true));
        $lines = ReportCsv::lines($build->handle($report, $options), ReportHeading::for($report, $options, $context, (string) $current->require()->name));

        return response()->streamDownload(fn () => ReportCsv::write($lines), ReportHeading::filename($report, $options), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function print(ReportRequest $request, ReportKind $report, CurrentCompany $current, ResolveCurrentBranch $switcher, BuildReport $build): Response
    {
        $context = BusinessContext::for($current, $switcher->handle($request->session()), $request->input('till'));
        $options = $this->withView($report, $request->options($current, $context['branch']['id'] ?? null, $context['till']['id'] ?? null, true));

        return Inertia::render('app/reports/print', [
            'report' => $this->meta($report),
            'filters' => $this->filters($options, $report),
            'heading' => ReportHeading::for($report, $options, $context, (string) $current->require()->name),
            'result' => $build->handle($report, $options)->toArray(),
        ]);
    }

    /** A tab the report has, else its first (none for most reports). */
    private function withView(ReportKind $report, ReportOptions $options): ReportOptions
    {
        $views = array_column($report->views(), 'value');
        $view = in_array($options->view, $views, true) ? $options->view : ($views[0] ?? '');

        return new ReportOptions($options->window, $options->group, $view, $options->page, $options->export);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(ReportKind $report): array
    {
        return [
            'value' => $report->value, 'label' => $report->label(), 'description' => $report->description(), 'section' => $report->section(),
            'usesDates' => $report->usesDates(), 'compares' => $report->compares(), 'groups' => $report->groups(), 'views' => $report->views(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(ReportOptions $options, ?ReportKind $report): array
    {
        return [...$options->window->toArray(), 'group' => $options->group->value, 'view' => $options->view, 'page' => $options->page, 'report' => $report?->value];
    }
}
