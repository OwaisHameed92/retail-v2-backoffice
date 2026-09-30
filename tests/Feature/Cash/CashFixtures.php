<?php

namespace Tests\Feature\Cash;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Module 5.4 test rows, written straight to the till tables as the push path stores them (UTC "Y-m-d H:i:s").
 */
final class CashFixtures
{
    public static function member(Company $company, CompanyRole $role, ?string $branchId = null): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $branchId]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function props(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode((string) json_encode($response->viewData('page')['props']), true);
    }

    /** Cash and card payment types of a business (ids `<prefix>CASH` / `<prefix>CARD`). */
    public static function paymentTypes(string $companyId): void
    {
        DB::table('payment_types')->insertOrIgnore([
            ['id' => substr($companyId, 0, 22).'CASH', 'company_id' => $companyId, 'name' => 'Cash', 'is_cash' => true, 'is_card' => false],
            ['id' => substr($companyId, 0, 22).'CARD', 'company_id' => $companyId, 'name' => 'Card', 'is_cash' => false, 'is_card' => true],
        ]);
    }

    public static function type(string $companyId, string $kind): string
    {
        return substr($companyId, 0, 22).$kind;
    }

    /**
     * A shift with a cash and a card reconciliation line.
     *
     * @param  array<string, mixed>  $shift
     * @param  array{0: string, 1: string, 2: string}|null  $cash  [expected, declared, variance]
     * @param  array{0: string, 1: string, 2: string}|null  $card  [expected, declared, terminal]
     */
    public static function shift(string $companyId, string $branchId, string $registerId, string $id, array $shift, ?array $cash = null, ?array $card = null): void
    {
        self::paymentTypes($companyId);
        DB::table('shifts')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => $registerId, 'user_id' => 'USER0000000000000000000001',
            'opened_at' => '2026-09-23 07:00:00', 'closed_at' => null, 'opening_float' => '100.00', 'status' => 'open', 'variance_total' => '0.00', ...$shift,
        ]);

        foreach (['CASH' => $cash, 'CARD' => $card] as $kind => $line) {
            if ($line !== null) {
                DB::table('shift_tenders')->insert([
                    'id' => substr($id, 0, 16).substr($id, -6).$kind, 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => $registerId, 'shift_id' => $id,
                    'payment_type_id' => self::type($companyId, $kind), 'expected' => $line[0], 'declared' => $line[1],
                    'terminal_total' => $kind === 'CARD' ? $line[2] : '0.00',
                    'variance' => $kind === 'CASH' ? $line[2] : bcsub($line[1], $line[0], 2),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function row(string $table, array $row): void
    {
        DB::table($table)->insert($row);
    }
}
