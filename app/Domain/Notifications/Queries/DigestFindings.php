<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Anomalies\Queries\AnomalyDigest;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything one business's daily digest can say (module 7.8), per alert type and per shop, computed once and then
 * cut per user (their shops and choices) by {@see DigestSections}. Shop key `''` = the whole business (only users
 * who see every shop), `*` = every shop (product recalls).
 *
 * - tillOffline / syncFailing: Till health alerts still open (module 2.7);
 * - lowStock: StockDigest; cashVariance: yesterday's variances (CashDigest); compliance: ComplianceDigest;
 * - syncConflicts: open portal conflicts and the tills' pending clashes (module 2.9B);
 * - unusualActivity: new anomaly findings of the last 24 hours (module 6.6, AnomalyDigest; staff-level ones under
 *   `staff:<shop id>`, for owners and managers only).
 */
final class DigestFindings
{
    /** Items listed per section; the rest is "and N more". */
    public const ITEMS = 8;

    public function __construct(private readonly StockDigest $stock, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  list<AlertType>  $types  only what someone wants
     * @return array<string, array<string, array{total: int, counts: array<string, int>, items: list<string>}>>
     */
    public function for(Company $company, array $types, CarbonImmutable $now): array
    {
        $shops = DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();

        if ($shops === []) {
            return [];
        }

        $yesterday = TradingDay::today($now)->subDay()->format('Y-m-d');

        return $this->tenancy->runAs($company, function () use ($company, $types, $shops, $now, $yesterday) {
            $out = [];

            foreach ($types as $type) {
                $out[$type->value] = match ($type) {
                    AlertType::TillOffline, AlertType::SyncFailing => $this->health($company->id, $type, $shops),
                    AlertType::LowStock => $this->stock->for($company->id, $shops),
                    AlertType::CashVariance => CashDigest::for($shops, $yesterday),
                    AlertType::Compliance => ComplianceDigest::for($shops, $now),
                    AlertType::SyncConflicts => $this->conflicts($company->id, $shops),
                    AlertType::MorningSummary => [], // its own facts (module 6.3, MorningFacts)
                    AlertType::UnusualActivity => AnomalyDigest::for($shops, $now),
                };
            }

            return array_filter($out);
        });
    }

    /**
     * @param  array<string, string>  $shops
     * @return array<string, array{total: int, counts: array<string, int>, items: list<string>}>
     */
    private function health(string $companyId, AlertType $type, array $shops): array
    {
        $out = [];

        foreach (UrgentSubjects::open([$companyId])[$companyId] ?? [] as $subject) {
            if ($subject->type !== $type || ! isset($shops[(string) $subject->branchId])) {
                continue;
            }

            $key = (string) $subject->branchId;
            $entry = $out[$key] ?? ['total' => 0, 'counts' => ['offline' => 0, 'failing' => 0, 'stalled' => 0], 'items' => []];
            $entry['total']++;
            $entry['counts'][match ($subject->problem) {
                'tillOffline' => 'offline', 'syncStalled' => 'stalled', default => 'failing'
            }]++;
            $entry['items'][] = $type === AlertType::TillOffline
                ? $subject->shopName.': '.($subject->tillName ?? 'a till').' offline since '.MailFormat::dateTime($subject->since)
                : $subject->shopName.': sync '.($subject->problem === 'syncStalled' ? 'stalled' : 'failing').' since '.MailFormat::dateTime($subject->since);
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $shops
     * @return array<string, array{total: int, counts: array<string, int>, items: list<string>}>
     */
    private function conflicts(string $companyId, array $shops): array
    {
        $portal = DB::table('sync_conflicts')->where('company_id', $companyId)->where('status', 'open')
            ->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) as n')->pluck('n', 'branch_id');
        $clashes = DB::table('till_sync_conflicts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', 'pending'))
            ->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) as n')->pluck('n', 'branch_id');
        $out = [];

        foreach ([...$portal->keys()->all(), ...$clashes->keys()->all()] as $branch) {
            $key = (string) $branch;

            if ($key !== '' && ! isset($shops[$key])) {
                continue;
            }

            $p = (int) ($portal[$branch] ?? 0);
            $c = (int) ($clashes[$branch] ?? 0);
            $name = $key === '' ? 'Whole business' : $shops[$key];
            $out[$key] = [
                'total' => $p + $c,
                'counts' => ['portal' => $p, 'shop' => $c],
                'items' => array_values(array_filter([
                    $p > 0 ? $name.': '.MailFormat::count($p, 'change').' kept out by the portal' : null,
                    $c > 0 ? $name.': '.MailFormat::count($c, 'clash', 'clashes').' waiting at the shop' : null,
                ])),
            ];
        }

        return $out;
    }
}
