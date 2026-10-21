<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldAccess;
use App\Domain\Handheld\Support\HandheldShops;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;

/**
 * Props of Settings → Handheld (PDA) users (`app/handheld-users/index`): the current business's handheld users (a
 * one-shop portal user sees their shop's only), each with their shop, signed-in devices and last use, the open shops
 * to choose from, the counts, and whether the business's plan includes the handheld app (`included`: when not, the
 * screen shows a notice and no Add). Never a password or token hash.
 */
final class HandheldUsersPage
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array{users: list<array<string, mixed>>, shops: list<array{id: string, name: string, code: string}>, stats: array<string, int>, included: bool}
     */
    public function handle(): array
    {
        $restricted = $this->tenancy->restrictedBranchId();
        $users = HandheldUser::query()->with('createdBy:id,name')
            ->when($restricted !== null, fn ($query) => $query->where('branch_id', $restricted))
            ->orderBy('name')->orderBy('id')->get();
        $shops = Branch::query()->withTrashed()->whereKey($users->pluck('branch_id')->unique()->values()->all())->get(['id', 'name', 'is_active', 'deleted_at'])->keyBy('id');
        $tokens = HandheldToken::query()->whereIn('handheld_user_id', $users->pluck('id')->all())->whereNull('revoked_at')
            ->get(['handheld_user_id', 'last_used_at', 'created_at'])->groupBy('handheld_user_id');

        $rows = $users->map(function (HandheldUser $user) use ($shops, $tokens) {
            $shop = $shops[$user->branch_id] ?? null;
            $live = $tokens[$user->id] ?? collect();
            $lastUsed = $live->map(fn (HandheldToken $t) => $t->last_used_at ?? $t->created_at)->filter()->max();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'shopId' => $user->branch_id,
                'shopName' => $shop?->name,
                'shopOpen' => $shop !== null && $shop->is_active && ! $shop->trashed(),
                'isActive' => $user->is_active,
                'canChangePrices' => $user->can_change_prices,
                'devices' => $live->count(),
                'lastLoginAt' => $user->last_login_at?->toIso8601ZuluString(),
                'lastUsedAt' => $lastUsed?->toIso8601ZuluString(),
                'createdAt' => $user->created_at?->toIso8601ZuluString(),
                'createdBy' => $user->createdBy?->name,
            ];
        })->values()->all();

        return [
            'users' => $rows,
            'shops' => HandheldShops::options()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'stats' => [
                'active' => $users->where('is_active', true)->count(),
                'inactive' => $users->where('is_active', false)->count(),
                'canChangePrices' => $users->where('is_active', true)->where('can_change_prices', true)->count(),
                'devices' => array_sum(array_column($rows, 'devices')),
            ],
            'included' => HandheldAccess::allowed($this->tenancy->require()),
        ];
    }
}
