<?php

namespace App\Domain\Audit\Data;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Audit log filters from the query string. Anything malformed is ignored (never an error page): the screens are
 * read-only and every value only narrows the list.
 *
 * - `actor`: `admin:<id>`, `user:<id>`, `staff` (any Switch & Save admin) or `system`;
 * - `company` (admin screen only): a business id;
 * - `action`: an exact action (`licence.suspended`) or a group (`licence.*`);
 * - `subjectType` / `subjectId`; `from` / `to`: days (Y-m-d, shop time zone); `search`: free text.
 */
final readonly class AuditFilters
{
    public function __construct(
        public ?string $actor = null,
        public ?string $company = null,
        public ?string $action = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
    ) {}

    public static function fromRequest(Request $request, bool $allowCompany): self
    {
        $actor = self::text($request, 'actor', 80);
        $action = self::text($request, 'action', 100);
        $company = self::text($request, 'company', 26);

        return new self(
            actor: $actor !== null && preg_match('/^(?:(?:admin|user):[A-Za-z0-9]{1,64}|staff|system)$/', $actor) === 1 ? $actor : null,
            company: $allowCompany && $company !== null && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $company) === 1 ? $company : null,
            action: $action !== null && preg_match('/^[A-Za-z0-9_.-]+(?:\.\*)?$/', $action) === 1 ? $action : null,
            subjectType: self::text($request, 'subjectType', 255),
            subjectId: self::text($request, 'subjectId', 64),
            from: self::day($request, 'from'),
            to: self::day($request, 'to'),
            search: self::text($request, 'search', 100),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor,
            'company' => $this->company,
            'action' => $this->action,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'from' => $this->from,
            'to' => $this->to,
            'search' => $this->search,
        ];
    }

    /** Start of the `from` day in UTC. */
    public function fromUtc(): ?CarbonImmutable
    {
        return $this->from !== null ? CarbonImmutable::parse($this->from, Country::zone())->startOfDay()->utc() : null;
    }

    /** Start of the day after `to`, in UTC (exclusive bound). */
    public function toUtcExclusive(): ?CarbonImmutable
    {
        return $this->to !== null ? CarbonImmutable::parse($this->to, Country::zone())->addDay()->startOfDay()->utc() : null;
    }

    private static function text(Request $request, string $key, int $max): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= $max ? $value : null;
    }

    private static function day(Request $request, string $key): ?string
    {
        $value = self::text($request, $key, 10);

        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
