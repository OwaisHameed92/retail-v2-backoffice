<?php

namespace App\Http\Middleware;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Salon\Support\SalonOnlineSwitch;
use App\Domain\Salon\Support\SalonPublicRateLimits;
use App\Domain\Salon\Support\SalonShops;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public salon pages' first step (W3a, routes/salon.php): `SALON_ONLINE` off → 404 for every page. Then finds the
 * shop by the `{slug}` in the address: unknown, no page published yet, shop closed down, business cancelled or no
 * longer a salon → 404. The shop goes in the request (`ResolveSalonShop::shop($request)`) and the `slug` route
 * parameter is dropped, so controllers get only their own parameters; one shop's address never reaches another's data.
 */
class ResolveSalonShop
{
    public const ATTRIBUTE = 'salon_online_shop';

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(SalonOnlineSwitch::enabled(), 404);

        $route = $request->route();
        $slug = $route instanceof Route ? (string) $route->parameter('slug') : '';
        $shop = SalonOnlineShops::bySlug($slug);

        abort_if($shop === null || $shop->page === null || ! self::live($shop), 404);

        $request->attributes->set(self::ATTRIBUTE, $shop);
        $request->attributes->set(SalonPublicRateLimits::SLUG_ATTRIBUTE, $slug);

        if ($route instanceof Route) {
            $route->forgetParameter('slug');
        }

        return $next($request);
    }

    public static function shop(Request $request): SalonOnlineShop
    {
        $shop = $request->attributes->get(self::ATTRIBUTE);

        if (! $shop instanceof SalonOnlineShop) {
            throw new \LogicException('The route is not behind ResolveSalonShop.');
        }

        return $shop;
    }

    private static function live(SalonOnlineShop $shop): bool
    {
        $branch = Branch::withoutCompanyScope()->find($shop->branch_id);
        $company = Company::query()->find($shop->company_id);

        return $branch !== null && $branch->is_active && $company !== null && $company->status !== CompanyStatus::Cancelled
            && SalonShops::isSalonBranch($shop->company_id, $shop->branch_id);
    }
}
