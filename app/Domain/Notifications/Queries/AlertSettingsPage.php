<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Notifications\Enums\AlertDelivery;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Models\AlertPreference;
use App\Domain\Notifications\Support\AlertRecipients;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * Props of Settings → Notifications (module 7.8): the alert types the user's role can get with their current choice
 * and the role's default, and the shops (picker for multi-shop users; a one-shop user's own shop, locked).
 * Runs inside the company scope.
 */
final class AlertSettingsPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, int $userId, string $email, CompanyRole $role, ?string $restrictedBranchId): array
    {
        $preference = AlertPreference::query()->where('user_id', $userId)->first();
        $deliveries = AlertRecipients::deliveries($role, $preference->deliveries ?? []);
        $shops = Branch::query()->orderBy('name')->get(['id', 'name']);
        $locked = $restrictedBranchId !== null ? $shops->firstWhere('id', $restrictedBranchId) : null;
        $picked = $preference?->branch_ids;

        return [
            'business' => $company->name,
            'email' => $email,
            'role' => $role->label(),
            'types' => array_map(fn (AlertType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'urgent' => $type->urgent(),
                'delivery' => $deliveries[$type->value]->value,
                'default' => $type->defaultFor($role)->value,
                'options' => array_map(fn (AlertDelivery $d) => ['value' => $d->value, 'label' => $d->label()], $type->deliveries()),
            ], AlertType::forRole($role)),
            'shops' => $restrictedBranchId !== null ? [] : $shops->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'allShops' => $restrictedBranchId === null && $picked === null,
            'selectedShops' => $restrictedBranchId === null ? ($picked ?? $shops->pluck('id')->all()) : [],
            'lockedShop' => $restrictedBranchId !== null ? ($locked->name ?? 'Your shop') : null,
            'digestTime' => '07:00',
        ];
    }
}
