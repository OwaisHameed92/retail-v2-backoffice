<?php

namespace App\Domain\Ai\Support\Portal;

use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * The date range and shop of a portal assistant tool (module 6.2), resolved exactly like the dashboard and the
 * reports (trading days in Europe/London, TradingRange clamps a custom range), so figures match the linked pages.
 */
final class ToolWindow
{
    /**
     * JSON schema properties: period, from, to, shop_id (and compare).
     *
     * @return array<string, mixed>
     */
    public static function properties(bool $compare = false): array
    {
        $properties = [
            'period' => [
                'type' => 'string',
                'enum' => array_map(fn (TradingPeriod $p) => $p->value, TradingPeriod::cases()),
                'description' => 'Trading days to read (UK dates). "custom" uses from and to. Default last7Days.',
            ],
            'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (with period "custom").'],
            'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD (with period "custom").'],
            'shop_id' => ShopPin::schema(),
        ];

        if ($compare) {
            $properties['compare'] = [
                'type' => 'string',
                'enum' => array_map(fn (TradingCompare $c) => $c->value, TradingCompare::cases()),
                'description' => 'What to compare with. Default previousPeriod.',
            ];
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $compare = false): array
    {
        $rules = [
            'period' => ['nullable', 'string', Rule::enum(TradingPeriod::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'shop_id' => ['nullable', 'string', new ValidUlid],
        ];

        if ($compare) {
            $rules['compare'] = ['nullable', 'string', Rule::enum(TradingCompare::class)];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function filters(array $input, ShopPin $shop, ?CarbonImmutable $now = null): BusinessDashboardFilters
    {
        $from = is_string($input['from'] ?? null) ? $input['from'] : null;
        $to = is_string($input['to'] ?? null) ? $input['to'] : null;
        $period = TradingPeriod::tryFrom((string) ($input['period'] ?? ''))
            ?? ($from !== null || $to !== null ? TradingPeriod::Custom : TradingPeriod::Last7Days);
        $compare = TradingCompare::tryFrom((string) ($input['compare'] ?? '')) ?? TradingCompare::PreviousPeriod;

        return BusinessDashboardFilters::resolve(
            app(CurrentCompany::class)->require()->getKey(), $period, $from ?? $to, $to ?? $from, $compare, $shop->id, null, $now,
        );
    }

    /**
     * The range as the tool reports it back (so the model quotes the real days, not the ones it asked for).
     *
     * @return array<string, string|int>
     */
    public static function describe(BusinessDashboardFilters $window): array
    {
        return [
            'from' => $window->from->toDateString(),
            'to' => $window->to->toDateString(),
            'days' => (int) $window->from->diffInDays($window->to) + 1,
            'label' => self::label($window->from, $window->to),
        ];
    }

    /** "3 October 2026" or "1 – 7 October 2026". */
    public static function label(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->equalTo($to)) {
            return $from->format('j F Y');
        }

        return $from->format($from->year === $to->year ? 'j F' : 'j F Y').' – '.$to->format('j F Y');
    }
}
