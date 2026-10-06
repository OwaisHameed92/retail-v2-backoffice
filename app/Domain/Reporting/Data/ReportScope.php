<?php

namespace App\Domain\Reporting\Data;

use App\Domain\Reporting\Models\ReportRow;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * What a report reads (DASHBOARD.md §2.1, §5 "SCOPE"): one business (or, for the admin area, every business),
 * optional shops and tills, and trading days `from … to` inclusive (dates in the shops' time zone).
 *
 * - `tenant()`: the current company (CurrentCompany, fail closed); the models' company scope applies as well. A
 *   shop manager's screen passes their one shop in `branchIds` (module 4.1 enforces it).
 * - `admin()`: super admin (3.2) only — across every business, or one business given by id, without a current
 *   company. Never build it from tenant input.
 *
 * `previousPeriod()`, `sameLastWeek()` and `sameLastYear()` give the compare windows of §2.9.
 */
final readonly class ReportScope
{
    /**
     * @param  list<string>|null  $branchIds  null = every shop
     * @param  list<string>|null  $registerIds  null = every till
     */
    private function __construct(
        public ?string $companyId,
        public bool $admin,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?array $branchIds = null,
        public ?array $registerIds = null,
    ) {
        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('The report range ends before it starts.');
        }
    }

    /**
     * @param  list<string>|null  $branchIds
     * @param  list<string>|null  $registerIds
     */
    public static function tenant(CarbonInterface|string $from, CarbonInterface|string $to, ?array $branchIds = null, ?array $registerIds = null): self
    {
        return new self(app(CurrentCompany::class)->require()->getKey(), false, self::day($from), self::day($to), $branchIds, $registerIds);
    }

    /**
     * @param  list<string>|null  $branchIds
     * @param  list<string>|null  $registerIds
     */
    public static function admin(?string $companyId, CarbonInterface|string $from, CarbonInterface|string $to, ?array $branchIds = null, ?array $registerIds = null): self
    {
        return new self($companyId, true, self::day($from), self::day($to), $branchIds, $registerIds);
    }

    public function between(CarbonInterface|string $from, CarbonInterface|string $to): self
    {
        return new self($this->companyId, $this->admin, self::day($from), self::day($to), $this->branchIds, $this->registerIds);
    }

    /** Number of trading days in the range. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** The same number of days immediately before `from`. */
    public function previousPeriod(): self
    {
        return $this->between($this->from->subDays($this->days()), $this->from->subDay());
    }

    public function sameLastWeek(): self
    {
        return $this->between($this->from->subDays(7), $this->to->subDays(7));
    }

    public function sameLastYear(): self
    {
        return $this->between($this->from->subYearNoOverflow(), $this->to->subYearNoOverflow());
    }

    /**
     * A base query on a reporting table with this scope applied (company, shops, tills, days). Columns are qualified
     * with the table name (the company scope qualifies its own that way), so joins must not alias the table.
     *
     * @param  class-string<ReportRow>  $model
     */
    public function query(string $model): Builder
    {
        $eloquent = $this->admin ? $model::withoutCompanyScope() : $model::query();
        $t = (new $model)->getTable();

        return $eloquent->toBase()
            ->when($this->companyId !== null, fn (Builder $q) => $q->where("{$t}.company_id", $this->companyId))
            ->when($this->branchIds !== null, fn (Builder $q) => $q->whereIn("{$t}.branch_id", $this->branchIds ?? []))
            ->when($this->registerIds !== null, fn (Builder $q) => $q->whereIn("{$t}.register_id", $this->registerIds ?? []))
            ->whereBetween("{$t}.trading_day", [$this->from->toDateString(), $this->to->toDateString()]);
    }

    private static function day(CarbonInterface|string $value): CarbonImmutable
    {
        return $value instanceof CarbonInterface
            ? CarbonImmutable::parse($value->format('Y-m-d'), 'UTC')
            : CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }
}
