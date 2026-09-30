<?php

namespace App\Domain\Accounts\Data;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The Accounts screens' filters (module 5.5), read leniently from the query string (a bad value is dropped).
 *
 * - `from` / `to`: London dates of the journal (`JournalEntry.date`); default this month so far; at most MAX_DAYS;
 * - `shop`: a shop id, `all`, or absent = the top-bar shop. A one-shop user always gets their own shop;
 * - `account`: an account code (journals); `refType`: a journal type, e.g. `Sale` (journals);
 * - `fix`: `1` = show the old refund entries (posted before the till's 0.1.15 refund fix) the right way round.
 */
final readonly class AccountsFilters
{
    public const MAX_DAYS = 1830;

    public function __construct(
        public string $from,
        public string $to,
        public ?string $shop = null,
        public ?string $account = null,
        public ?string $refType = null,
        public bool $fix = false,
        public bool $shopLocked = false,
    ) {}

    public static function fromRequest(Request $request, CurrentCompany $tenancy, ?string $currentShop): self
    {
        $today = TradingDay::today();
        $from = self::day($request->query('from')) ?? $today->startOfMonth()->format('Y-m-d');
        $to = self::day($request->query('to')) ?? $today->format('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(self::MAX_DAYS - 1)->format('Y-m-d');
        }

        $restricted = $tenancy->restrictedBranchId();
        $shop = $request->query('shop');
        $account = $request->query('account');
        $refType = $request->query('refType');

        return new self(
            from: $from,
            to: $to,
            shop: match (true) {
                $restricted !== null => $restricted,
                $shop === 'all' => null,
                is_string($shop) && preg_match('/^[0-9A-Za-z]{26}$/', $shop) === 1 => $shop,
                default => $currentShop,
            },
            account: is_string($account) && preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $account) === 1 ? $account : null,
            refType: is_string($refType) && preg_match('/^[A-Za-z]{1,40}$/', $refType) === 1 ? $refType : null,
            fix: $request->query('fix') === '1',
            shopLocked: $restricted !== null,
        );
    }

    /** The same filters for another date range (the VAT quarter). */
    public function between(string $from, string $to): self
    {
        return new self($from, $to, $this->shop, $this->account, $this->refType, $this->fix, $this->shopLocked);
    }

    /** @return list<string>|null the shops for a `ReportScope` (null = every shop) */
    public function branchIds(): ?array
    {
        return $this->shop === null ? null : [$this->shop];
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'shop' => $this->shop, 'account' => $this->account,
            'refType' => $this->refType, 'fix' => $this->fix, 'shopLocked' => $this->shopLocked,
        ];
    }

    public static function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value ? $value : null;
    }
}
