<?php

namespace App\Domain\Ai\Support\Portal;

use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Reports\ReportKind;
use Illuminate\Container\Attributes\Scoped;

/**
 * The portal pages behind an assistant answer (module 6.2). Each read tool adds the page that shows the same figures
 * with the same filters; the answer lists them as "Open report" links. Collected per request (scoped), reset by
 * AskPortalAssistant at the start of every question, and stored on the answer so history keeps them.
 *
 * Report pages (`/app/reports/*`) take their shop from the top-bar switcher, not the URL: such a link carries the
 * shop and `switchShop`, and the panel switches the shop before opening it (never for a one-shop user, whose shop is
 * fixed by the server anyway). Other pages take `shop` in the query string.
 *
 * @phpstan-type Link array{label: string, href: string, shopId: string|null, shopName: string, switchShop: bool}
 */
#[Scoped]
final class AssistantLinks
{
    public const MAX = 6;

    /** @var array<string, Link> */
    private array $links = [];

    public function reset(): void
    {
        $this->links = [];
    }

    /**
     * @param  array<string, string|int|null>  $query
     */
    public function add(string $label, string $path, array $query, ShopPin $shop, bool $switchShop = false): void
    {
        $query = array_filter($query, fn (mixed $v) => $v !== null && $v !== '');
        $href = $path.($query === [] ? '' : '?'.http_build_query($query));
        $key = $href.'|'.($switchShop ? (string) $shop->id : '');

        if (! isset($this->links[$key]) && count($this->links) < self::MAX) {
            $this->links[$key] = [
                'label' => $label,
                'href' => $href,
                'shopId' => $shop->id,
                'shopName' => $shop->name,
                'switchShop' => $switchShop && ! $shop->pinned,
            ];
        }
    }

    /**
     * A report page of module 4.8 with the tool's exact days (custom range, so the link still shows the same figures
     * tomorrow) and compare window.
     *
     * @param  array<string, string|int|null>  $extra
     */
    public function report(ReportKind $kind, BusinessDashboardFilters $window, ShopPin $shop, array $extra = []): void
    {
        $query = $kind->usesDates()
            ? ['period' => 'custom', 'from' => $window->from->toDateString(), 'to' => $window->to->toDateString(), 'compare' => $kind->compares() ? $window->compare->value : null]
            : [];
        $label = $kind->label().($kind->usesDates() ? ', '.ToolWindow::label($window->from, $window->to) : '').' · '.$shop->name;

        $this->add($label, '/app/reports/'.$kind->value, [...$query, ...$extra], $shop, switchShop: true);
    }

    /**
     * @return list<Link>
     */
    public function all(): array
    {
        return array_values($this->links);
    }
}
