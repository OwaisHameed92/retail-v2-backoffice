<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Compliance\Support\Expiry;
use App\Domain\Compliance\Support\MissedChecks;
use App\Domain\Compliance\Support\RecallShops;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\TillData\Models\ComplianceLicence;
use App\Domain\TillData\Models\DiaryCheckDefinition;
use App\Domain\TillData\Models\IncidentReport;
use App\Domain\TillData\Models\ProductRecall;
use App\Domain\TillData\Models\TrainingRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Compliance overview (module 5.7): headline figures and the "needs attention" reminders: licences and training
 * expired or expiring soon (Expiry), diary checks missed in the last 7 days (MissedChecks) and open recalls. For the
 * shop picked (a one-shop user: theirs); the dates filter does not apply (it is always "now").
 */
final class ComplianceOverview
{
    public const MISSED_DAYS = 7;

    private const LIMIT = 12;

    /**
     * @return array<string, mixed>
     */
    public static function for(ComplianceFilters $f, CarbonImmutable $now): array
    {
        $today = TradingDay::today($now);
        $week = new ComplianceFilters($today->subDays(self::MISSED_DAYS - 1)->format('Y-m-d'), $today->format('Y-m-d'), $f->shop, shopLocked: $f->shopLocked);
        $month = new ComplianceFilters($today->subDays(ComplianceFilters::DEFAULT_DAYS - 1)->format('Y-m-d'), $today->format('Y-m-d'), $f->shop, shopLocked: $f->shopLocked);

        $licences = self::expiring($f->scope(ComplianceLicence::query()), Expiry::LICENCE_SOON_DAYS)->get();
        $training = self::expiring($f->scope(TrainingRecord::query()), Expiry::TRAINING_SOON_DAYS)->get();
        $definitions = $f->scope(DiaryCheckDefinition::query())->where('is_active', true)->get();
        $missed = collect(MissedChecks::forDefinitions($definitions, $week->from, $week->to, $now))
            ->map(fn (array $periods) => collect($periods)->where('state', 'missed')->count())->filter();
        $recalls = RecallShops::where(ProductRecall::query(), RecallShops::ids($f->shop), true)->orderByDesc('raised_at')->get();
        $refusals = AgeChecks::refusals($week)->count();
        $checks = AgeChecks::checks($week)->distinct()->count('s.id');

        $shops = L::shops([...$licences->pluck('branch_id'), ...$training->pluck('branch_id'), ...$definitions->pluck('branch_id')]);
        $staff = L::staff($training->pluck('user_id'));
        $where = fn (?string $branch) => $f->shop === null && ($name = L::name($shops, $branch)) !== null ? ' · '.$name : '';

        $items = collect()
            ->concat($licences->map(fn (ComplianceLicence $l) => self::expiryItem('lic-'.$l->id, 'Licence', (L::blank($l->licence_type) ?? 'Licence').' '.(L::blank($l->number) ?? '').$where($l->branch_id), $l->expires_on, Expiry::LICENCE_SOON_DAYS, route('app.compliance.licences'))))
            ->concat($training->map(fn (TrainingRecord $t) => self::expiryItem('trn-'.$t->id, 'Training', (L::name($staff, $t->user_id) ?? 'Unknown staff').': '.(L::blank($t->topic) ?? 'training').$where($t->branch_id), $t->expires_on, Expiry::TRAINING_SOON_DAYS, route('app.compliance.training'))))
            ->concat($missed->map(function (int $n, string $id) use ($definitions, $where, $week) {
                $d = $definitions->firstWhere('id', $id);

                return ['id' => 'chk-'.$id, 'label' => 'Check', 'tone' => 'warning', 'text' => (L::blank($d?->name) ?? 'Diary check').$where($d?->branch_id).' missed '.($n === 1 ? 'once' : "{$n} times").' in the last '.self::MISSED_DAYS.' days', 'sort' => 1, 'href' => route('app.compliance.diary', ['from' => $week->from, 'to' => $week->to])];
            })->values())
            ->concat($recalls->map(fn (ProductRecall $r) => ['id' => 'rcl-'.$r->id, 'label' => 'Recall', 'tone' => 'danger', 'text' => (L::blank($r->product_name) ?? 'Product').(L::blank($r->batch_code) ? ' batch '.$r->batch_code : '').' is recalled', 'sort' => 0, 'href' => route('app.compliance.recalls.show', $r->id)]))
            ->sortBy('sort')->values();

        return [
            'attention' => ['items' => $items->take(self::LIMIT)->map(fn (array $i) => array_diff_key($i, ['sort' => true]))->all(), 'total' => $items->count()],
            'figures' => [
                'refusals' => $refusals,
                'refusalRate' => L::rate($refusals, $checks + $refusals),
                'incidents' => $month->during($month->scope(IncidentReport::query()), 'occurred_at')->count(),
                'licencesDue' => $licences->count(),
                'trainingDue' => $training->count(),
                'missedChecks' => (int) $missed->sum(),
                'openRecalls' => $recalls->count(),
            ],
            'windows' => ['week' => ['from' => $week->from, 'to' => $week->to], 'month' => ['from' => $month->from, 'to' => $month->to]],
        ];
    }

    /**
     * Rows expired or expiring within `$soonDays`, soonest first.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function expiring(Builder $query, int $soonDays): Builder
    {
        return $query->whereNotNull('expires_on')->where('expires_on', '<=', TradingDay::today()->addDays($soonDays)->format('Y-m-d'))->orderBy('expires_on');
    }

    /**
     * @return array{id: string, label: string, tone: string, text: string, sort: int, href: string}
     */
    private static function expiryItem(string $id, string $label, string $what, ?CarbonImmutable $expiresOn, int $soonDays, string $href): array
    {
        $expired = Expiry::status($expiresOn, $soonDays) === 'expired';
        $days = Expiry::daysLeft($expiresOn) ?? 0;
        $when = match (true) {
            $expired => 'expired '.$expiresOn?->format('j M Y'),
            $days === 0 => 'expires today',
            default => 'expires '.$expiresOn?->format('j M Y').' ('.$days.($days === 1 ? ' day' : ' days').')',
        };

        return ['id' => $id, 'label' => $label, 'tone' => $expired ? 'danger' : 'warning', 'text' => trim($what).' '.$when, 'sort' => $expired ? 0 : 2, 'href' => $href];
    }
}
