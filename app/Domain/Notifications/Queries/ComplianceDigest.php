<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Compliance\Support\Expiry;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\ComplianceLicence;
use App\Domain\TillData\Models\ProductRecall;
use App\Domain\TillData\Models\TrainingRecord;
use Carbon\CarbonImmutable;

/**
 * Compliance part of the daily digest (module 7.8, from module 5.7): staff training and licences that expired in the
 * last {@see self::EXPIRED_DAYS} days or expire within {@see self::SOON_DAYS}, per shop, and open product recalls
 * (every shop: key `*`). Older expiries are left to the Compliance screen so a renewed record does not nag daily.
 * Run as the business (DigestFindings sets CurrentCompany).
 */
final class ComplianceDigest
{
    public const SOON_DAYS = 14;

    public const EXPIRED_DAYS = 30;

    /**
     * @param  array<string, string>  $shops  active shop names by id
     * @return array<string, array{total: int, counts: array<string, int>, items: list<string>}>
     */
    public static function for(array $shops, CarbonImmutable $now): array
    {
        $today = TradingDay::today($now);
        $window = fn ($query) => $query->whereIn('branch_id', array_keys($shops))->whereNotNull('expires_on')
            ->where('expires_on', '>=', $today->subDays(self::EXPIRED_DAYS)->format('Y-m-d'))
            ->where('expires_on', '<=', $today->addDays(self::SOON_DAYS)->format('Y-m-d'))->orderBy('expires_on');
        $licences = $window(ComplianceLicence::query())->get();
        $training = $window(TrainingRecord::query())->get();
        $staff = L::staff($training->pluck('user_id'));
        $out = [];

        $add = function (string $branchId, string $text, ?CarbonImmutable $expiresOn) use (&$out, $shops, $today): void {
            $expired = Expiry::status($expiresOn, self::SOON_DAYS, $today) === 'expired';
            $days = Expiry::daysLeft($expiresOn, $today) ?? 0;
            $when = match (true) {
                $expired => 'expired '.$expiresOn?->format('j M Y'),
                $days === 0 => 'expires today',
                default => 'expires '.$expiresOn?->format('j M Y').' ('.$days.($days === 1 ? ' day' : ' days').')',
            };
            $entry = $out[$branchId] ?? ['total' => 0, 'counts' => ['expired' => 0, 'expiring' => 0, 'recalls' => 0], 'items' => []];
            $entry['total']++;
            $entry['counts'][$expired ? 'expired' : 'expiring']++;
            $entry['items'][] = $shops[$branchId].': '.trim($text).' '.$when;
            $out[$branchId] = $entry;
        };

        foreach ($licences as $l) {
            $add((string) $l->branch_id, (L::blank($l->licence_type) ?? 'Licence').' '.(L::blank($l->number) ?? ''), $l->expires_on);
        }

        foreach ($training as $t) {
            $add((string) $t->branch_id, (L::name($staff, $t->user_id) ?? 'Unknown staff').': '.(L::blank($t->topic) ?? 'training'), $t->expires_on);
        }

        $recalls = ProductRecall::query()->where('status', ProductRecallStatus::Open->value)->orderByDesc('raised_at')->get();

        if ($recalls->isNotEmpty()) {
            $out['*'] = [
                'total' => $recalls->count(),
                'counts' => ['expired' => 0, 'expiring' => 0, 'recalls' => $recalls->count()],
                'items' => $recalls->map(fn (ProductRecall $r) => 'Recall: '.(L::blank($r->product_name) ?? 'Product').(L::blank($r->batch_code) ? ' batch '.$r->batch_code : ''))->values()->all(),
            ];
        }

        return $out;
    }
}
