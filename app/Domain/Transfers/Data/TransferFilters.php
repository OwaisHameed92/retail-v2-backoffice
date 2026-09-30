<?php

namespace App\Domain\Transfers\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Transfers\Support\TransferState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The stock transfer filters (module 5.3), read leniently from the query string: an unknown value is ignored, never an
 * error. `shop` matches a transfer from or to that shop; `flow` (`out` = sent by it, `in` = sent to it) narrows
 * that. `from` / `to` are London days. A one-shop user is always pinned to their own shop, whatever the URL says.
 */
final readonly class TransferFilters
{
    public const FLOWS = ['in', 'out'];

    public function __construct(
        public ?string $shop = null,
        public ?string $flow = null,
        public ?string $status = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $pinned = false,
    ) {}

    /**
     * @param  array{0: string|null, 1: string|null}  $defaultDays  the days used when the URL has none
     */
    public static function from(Request $request, array $defaultDays = [null, null]): self
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $text = fn (string $key) => is_string($request->query($key)) && $request->query($key) !== '' ? (string) $request->query($key) : null;
        $shop = $restricted ?? $text('shop');
        $flow = $text('flow');
        $status = $text('status');
        $from = self::day($text('from')) ?? $defaultDays[0];
        $to = self::day($text('to')) ?? $defaultDays[1];

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            $shop,
            $shop !== null && in_array($flow, self::FLOWS, true) ? $flow : null,
            in_array($status, TransferState::STATES, true) ? $status : null,
            $from,
            $to,
            $restricted !== null,
        );
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} the UTC window [from, to) of the London days */
    public function window(): array
    {
        return [
            $this->from === null ? null : TradingDay::window($this->from)[0],
            $this->to === null ? null : TradingDay::window($this->to)[1],
        ];
    }

    /** @return array{shop: string|null, flow: string|null, status: string|null, from: string|null, to: string|null} */
    public function toArray(): array
    {
        return ['shop' => $this->shop, 'flow' => $this->flow, 'status' => $this->status, 'from' => $this->from, 'to' => $this->to];
    }

    private static function day(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
