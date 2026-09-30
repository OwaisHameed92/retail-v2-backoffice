<?php

namespace Tests\Feature\Customers;

use App\Domain\Shared\Support\Ulid;
use Illuminate\Support\Facades\DB;

/** Module 4.4: till-shaped ledger and consent rows written straight to the tables (as a push stores them). */
final class CustomerFixtures
{
    public static function ledger(string $companyId, string $branchId, string $customerId, string $type, string $amount, int $points, string $at, bool $deleted = false): string
    {
        $id = Ulid::new();
        DB::table('customer_transactions')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customerId, 'type' => $type,
            'amount' => $amount, 'points' => $points, 'sale_id' => '', 'balance_after' => '999.99', 'points_after' => 9999,
            'user_id' => '', 'note' => '', 'at' => $at, 'row_version' => 1, 'created_at' => $at, 'updated_at' => $at,
            'deleted_at' => $deleted ? $at : null,
        ]);

        return $id;
    }

    public static function consent(string $companyId, string $branchId, string $customerId, string $channel, bool $given, string $at, ?string $withdrawnAt = null, string $source = 'till'): string
    {
        $id = Ulid::new();
        DB::table('consents')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $customerId, 'channel' => $channel,
            'given' => $given, 'source' => $source, 'at' => $at, 'withdrawn_at' => $withdrawnAt, 'is_active' => true,
            'row_version' => 1, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }
}
