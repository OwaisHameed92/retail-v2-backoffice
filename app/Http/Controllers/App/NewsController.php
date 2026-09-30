<?php

namespace App\Http\Controllers\App;

use App\Domain\News\Queries\NewsDeliveryDetail;
use App\Domain\News\Queries\NewsPage;
use App\Domain\News\Queries\WeeklySummary;
use App\Domain\News\Support\NewsWeek;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\NewsDelivery;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Newspapers and magazines on the tenant portal (module 5.8, `company.can:news.view`): titles, the shops' deliveries,
 * returns and credits, vouchers, and the weekly summary. A one-shop user sees only their shop (another shop's delivery
 * is "not found"). Titles are changed in NewsTitleController.
 */
class NewsController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function home(): RedirectResponse
    {
        return redirect()->route('app.news.summary');
    }

    public function index(Request $request, string $kind): Response
    {
        return Inertia::render('app/news/index', NewsPage::for($request, $kind));
    }

    public function delivery(string $delivery): Response
    {
        $model = NewsDelivery::query()->findOrFail($delivery);
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $model->branch_id !== $restricted, 404);

        return Inertia::render('app/news/delivery', [...NewsDeliveryDetail::for($model), ...NewsPage::shared()]);
    }

    public function summary(Request $request): Response
    {
        $shop = $request->query('shop');
        $shop = $this->tenancy->restrictedBranchId() ?? (is_string($shop) && $shop !== '' ? $shop : null);

        return Inertia::render('app/news/summary', [
            ...WeeklySummary::for(NewsWeek::from($request->query('week')), $shop), 'shop' => $shop, ...NewsPage::shared(),
        ]);
    }
}
