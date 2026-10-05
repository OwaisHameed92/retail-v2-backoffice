<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Data\BillingStatusData;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The admin billing overview's "Businesses by billing state": every business that is not cancelled, with its
 * billing state (BillingStatus) as a badge, counts per filter group (paid, trial, waiting for Direct Debit, overdue,
 * setup fee due, suspended) and the rows of the chosen group, most urgent first.
 */
final class BillingStateOverview
{
    public const GROUPS = ['paid', 'trial', 'waitingForDirectDebit', 'overdue', 'setupDue', 'suspended'];

    private const ORDER = ['suspended', 'overdue', 'waitingForDirectDebit', 'setupDue', 'trial', 'paid'];

    private const LIMIT = 100;

    /**
     * @return array{counts: array<string, int>, total: int, group: string|null, rows: list<array<string, mixed>>, truncated: bool}
     */
    public static function for(?string $group, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $group = in_array($group, self::GROUPS, true) ? $group : null;
        $counts = array_fill_keys(self::GROUPS, 0);
        $rows = [];

        $companies = Company::query()->where('status', '!=', CompanyStatus::Cancelled->value)->orderBy('name')->get();

        foreach ($companies as $company) {
            $data = BillingStatusData::for(BillingStatus::for($company, $now));
            $counts[$data['group']] = ($counts[$data['group']] ?? 0) + 1;

            if ($group !== null && $data['group'] !== $group) {
                continue;
            }

            $rows[] = [
                'id' => $company->id,
                'name' => $company->name,
                'demo' => $data['demo'],
                'planType' => $data['planType']['label'] ?? null,
                'state' => $data['state'],
                'group' => $data['group'],
                'tone' => $data['tone'],
                'headline' => $data['headline'],
                'nextDate' => $data['next']['date'] ?? null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [array_search($a['group'], self::ORDER, true), $a['nextDate'] ?? '9999', $a['name']]
            <=> [array_search($b['group'], self::ORDER, true), $b['nextDate'] ?? '9999', $b['name']]);

        return [
            'counts' => $counts,
            'total' => $companies->count(),
            'group' => $group,
            'rows' => array_slice($rows, 0, self::LIMIT),
            'truncated' => count($rows) > self::LIMIT,
        ];
    }
}
