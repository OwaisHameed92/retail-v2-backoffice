<?php

namespace App\Domain\Purchasing\Reorder;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Support\AiGate;
use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\Department;
use App\Models\User;

/**
 * Props for /app/purchasing/suggestions (module 6.4): the filters, the suggestion lines grouped by shop and supplier,
 * totals, the lines worth a look, and what the user may do (create orders: `purchasing.manage` and every shop; the AI
 * note: `ai.use` and AI available for the business).
 */
final class ReorderPage
{
    /** @return array<string, mixed> */
    public static function for(ReorderFilters $filters, User $user): array
    {
        $result = app(ReorderSuggestions::class)->handle($filters);
        $lines = $result['lines'];
        $ordered = array_values(array_filter($lines, fn (array $l) => $l['suggestedCases'] > 0));
        $tenancy = app(CurrentCompany::class);

        return [
            'filters' => $filters->toArray(),
            'lines' => $lines,
            'groups' => $result['groups'],
            'truncated' => $result['truncated'],
            'notable' => $result['notable'],
            'stats' => [
                'lines' => count($ordered),
                'orders' => count(array_unique(array_column($ordered, 'groupKey'))),
                'cost' => Money::sum(array_column($ordered, 'suggestedCost'), 2),
                'attention' => $result['attention'],
            ],
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => (string) $d->name])->values()->all(),
            'settings' => [
                'historyWeeks' => max(1, (int) config('reorder.history_weeks', 8)),
                'reviewDays' => max(1, (int) config('reorder.review_days', 7)),
            ],
            'ai' => ['available' => $tenancy->can(Ability::AiUse) && app(AiGate::class)->isAvailable(AiContext::forUser($user, $tenancy->require(), AiFeature::ReorderSuggestions))],
            ...PurchasingPage::shared(),
        ];
    }
}
