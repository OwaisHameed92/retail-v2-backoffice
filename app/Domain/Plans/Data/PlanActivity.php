<?php

namespace App\Domain\Plans\Data;

use App\Domain\Admin\Models\Admin;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The "Activity" card on the plan detail page: audit entries for one plan, newest first, in plain English.
 */
final class PlanActivity
{
    /** Labels for changed fields, in display order. */
    private const LABELS = [
        'name' => 'Name',
        'code' => 'Code',
        'description' => 'Description',
        'pricing_mode' => 'Pricing',
        'price_monthly' => 'Monthly price',
        'price_yearly' => 'Yearly price',
        'setup_fee' => 'Setup fee',
        'currency' => 'Currency',
        'trial_days' => 'Trial length',
        'trial_grace_days' => 'Trial grace',
        'grace_days' => 'Payment grace',
        'features' => 'Features',
        'is_active' => 'Available for new licences',
        'is_public' => 'Shown on pricing page',
        'sort_order' => 'Sort order',
    ];

    /**
     * @return list<array{id: string, action: string, summary: string, actorName: string, changes: list<array{label: string, from: string|null, to: string}>, createdAt: string|null}>
     */
    public static function forPlan(Plan $plan, int $limit = 25): array
    {
        /** @var Collection<int, AuditLog> $entries */
        $entries = AuditLog::query()
            ->where('subject_type', $plan->getMorphClass())
            ->where('subject_id', $plan->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        $admins = Admin::query()
            ->whereIn('id', $entries->where('actor_type', (new Admin)->getMorphClass())->pluck('actor_id')->filter()->unique())
            ->pluck('name', 'id');

        return $entries->map(fn (AuditLog $entry) => [
            'id' => $entry->id,
            'action' => $entry->action,
            'summary' => self::summary($entry),
            'actorName' => $entry->actor_id === null ? 'System' : (string) ($admins[$entry->actor_id] ?? 'Unknown user'),
            'changes' => $entry->action === 'plan.updated' ? self::changes($entry->before ?? [], $entry->after ?? []) : [],
            'createdAt' => $entry->created_at?->toIso8601String(),
        ])->values()->all();
    }

    private static function summary(AuditLog $entry): string
    {
        return match ($entry->action) {
            'plan.created' => 'Created the plan',
            'plan.updated' => 'Updated the plan',
            'plan.archived' => 'Archived the plan',
            'plan.restored' => 'Restored the plan',
            'plan.duplicated' => 'Created this plan as a copy of '.((string) ($entry->meta['source_plan_name'] ?? 'another plan')),
            default => Str::of($entry->action)->after('.')->replace('_', ' ')->ucfirst()->value(),
        };
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{label: string, from: string|null, to: string}>
     */
    private static function changes(array $before, array $after): array
    {
        $changes = [];

        foreach (self::LABELS as $key => $label) {
            if (! array_key_exists($key, $after)) {
                continue;
            }

            if ($key === 'features') {
                $changes[] = ['label' => $label, 'from' => null, 'to' => self::featureChange($before[$key] ?? [], $after[$key] ?? [])];

                continue;
            }

            $changes[] = ['label' => $label, 'from' => self::format($key, $before[$key] ?? null), 'to' => self::format($key, $after[$key])];
        }

        return $changes;
    }

    private static function format(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'None';
        }

        return match (true) {
            str_starts_with($key, 'price_'), $key === 'setup_fee' => self::money($value),
            str_ends_with($key, '_days') => $value.' '.Str::plural('day', (int) $value),
            is_bool($value) => $value ? 'Yes' : 'No',
            $key === 'description' => Str::limit((string) $value, 80),
            $key === 'pricing_mode' => PricingMode::tryFrom((string) $value)?->label() ?? (string) $value,
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }

    /** "1234.5" → "£1,234.50" without going through a float. */
    private static function money(mixed $value): string
    {
        $amount = Money::normalise($value);
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = explode('.', ltrim($amount, '-'));

        return ($negative ? '-' : '').'£'.number_format((int) $whole).'.'.$fraction;
    }

    private static function featureChange(mixed $before, mixed $after): string
    {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];

        $added = self::labels(array_diff($after, $before));
        $removed = self::labels(array_diff($before, $after));

        return collect([
            $added === '' ? null : "Added {$added}",
            $removed === '' ? null : "Removed {$removed}",
        ])->filter()->implode('. ') ?: 'No change';
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function labels(array $values): string
    {
        return implode(', ', array_map(fn (Feature $feature) => $feature->label(), Feature::normalise($values)));
    }
}
