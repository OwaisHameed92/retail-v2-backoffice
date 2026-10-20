<?php

namespace App\Domain\Handheld\Data;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * The `user` and `tenant` blocks of `POST /api/pda/login` and `GET /api/pda/me` (legacy shape; ids are ULID
 * strings, `shop_id` is the portal's shop id).
 */
final class HandheldSession
{
    /**
     * @return array{user: array<string, mixed>, tenant: array<string, mixed>}
     */
    public static function of(HandheldUser $user, Company $company, Branch $shop): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'shop_id' => $shop->id,
                'shop_name' => $shop->name,
                'shop_code' => $shop->code,
                'can_change_prices' => $user->can_change_prices,
                'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            ],
            'tenant' => [
                'id' => $company->id,
                'name' => $company->name,
            ],
        ];
    }
}
