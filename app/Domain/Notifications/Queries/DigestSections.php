<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Anomalies\Queries\AnomalyDigest;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Notifications\Data\Recipient;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Support\AlertLinks;

/**
 * Cuts a business's digest findings (DigestFindings) down to one user (module 7.8): only the types they get in the
 * digest, only their shops (a one-shop user: theirs; business-wide things only for users who see every shop), at
 * most {@see DigestFindings::ITEMS} lines per section.
 */
final class DigestSections
{
    /**
     * @param  array<string, array<string, array{total: int, counts: array<string, int>, items: list<string>}>>  $findings
     * @return list<array{type: string, title: string, summary: string, items: list<string>, more: int, url: string, unsubscribeUrl: string}>
     */
    public static function for(Recipient $recipient, string $companyId, array $findings): array
    {
        $sections = [];

        foreach (AlertType::cases() as $type) {
            if (! $recipient->inDigest($type) || ! isset($findings[$type->value])) {
                continue;
            }

            $total = 0;
            $counts = [];
            $items = [];
            $shops = [];

            foreach ($findings[$type->value] as $shop => $entry) {
                $shop = (string) $shop;

                if (str_starts_with($shop, AnomalyDigest::STAFF)) {
                    if (! AnomalyVisibility::seesStaff($recipient->role)) {
                        continue; // staff-level findings: owners and managers only (module 6.6)
                    }

                    $shop = substr($shop, strlen(AnomalyDigest::STAFF));
                }

                if ($shop !== '*' && ! $recipient->covers($shop === '' ? null : $shop)) {
                    continue;
                }

                $total += $entry['total'];
                $items = [...$items, ...$entry['items']];
                $shops[] = $shop;

                foreach ($entry['counts'] as $key => $n) {
                    $counts[$key] = ($counts[$key] ?? 0) + $n;
                }
            }

            if ($total === 0) {
                continue;
            }

            $single = count($shops) === 1 && ! in_array($shops[0], ['', '*'], true) ? $shops[0] : $recipient->restrictedBranchId;
            $shown = array_slice($items, 0, DigestFindings::ITEMS);
            $sections[] = [
                'type' => $type->value,
                'title' => $type->label(),
                'summary' => self::summary($type, $counts),
                'items' => $shown,
                'more' => max(0, $total - count($shown)),
                'url' => AlertLinks::forType($type, $single),
                'unsubscribeUrl' => AlertLinks::unsubscribe($companyId, $recipient->userId, $type),
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, int>  $c
     */
    public static function summary(AlertType $type, array $c): string
    {
        $parts = fn (array $bits) => implode(', ', array_filter($bits)).'.';

        return match ($type) {
            AlertType::TillOffline => MailFormat::count($c['offline'] ?? 0, 'till').' still offline.',
            AlertType::SyncFailing => $parts([
                ($c['failing'] ?? 0) > 0 ? 'Sync failing at '.MailFormat::count($c['failing'], 'shop') : null,
                ($c['stalled'] ?? 0) > 0 ? 'stalled at '.MailFormat::count($c['stalled'], 'shop') : null,
            ]),
            AlertType::LowStock => $parts([
                MailFormat::count($c['low'] ?? 0, 'product line').' at or below the low-stock point',
                ($c['out'] ?? 0) > 0 ? ($c['out'] - ($c['negative'] ?? 0)).' out of stock' : null,
                ($c['negative'] ?? 0) > 0 ? $c['negative'].' below zero' : null,
            ]),
            AlertType::CashVariance => MailFormat::count($c['count'] ?? 0, 'difference').' over your alert amount yesterday'
                .(($c['short'] ?? 0) > 0 ? ' ('.$c['short'].' short)' : '').'.',
            AlertType::Compliance => $parts([
                ($c['expired'] ?? 0) > 0 ? $c['expired'].' expired' : null,
                ($c['expiring'] ?? 0) > 0 ? $c['expiring'].' expiring within '.ComplianceDigest::SOON_DAYS.' days' : null,
                ($c['recalls'] ?? 0) > 0 ? MailFormat::count($c['recalls'], 'open recall') : null,
            ]),
            AlertType::SyncConflicts => $parts([
                ($c['portal'] ?? 0) > 0 ? MailFormat::count($c['portal'], 'change').' from the shops waiting for your decision' : null,
                ($c['shop'] ?? 0) > 0 ? MailFormat::count($c['shop'], 'clash', 'clashes').' waiting at the tills' : null,
            ]),
            // Not a digest section: the morning summary has its own block in the same email (module 6.3).
            AlertType::MorningSummary => '',
            AlertType::UnusualActivity => $parts([
                MailFormat::count(($c['high'] ?? 0) + ($c['medium'] ?? 0) + ($c['low'] ?? 0), 'new finding').' far from normal',
                ($c['high'] ?? 0) > 0 ? $c['high'].' serious' : null,
            ]),
        };
    }
}
