<?php

namespace Tests\Feature\Compliance;

use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 5.7 test rows, written straight to the till tables as the push path stores them (UTC "Y-m-d H:i:s").
 * Ids are 26 characters: a prefix padded with zeros.
 */
final class ComplianceFixtures
{
    public const ALI = '01K5T0Q8C4000000000000U001';

    public const BEA = '01K5T0Q8C4000000000000U002';

    public static function id(string $tag): string
    {
        return str_pad('01K5', 26 - strlen($tag), '0').$tag;
    }

    /** Two till staff members of the business. */
    public static function staff(string $companyId): void
    {
        DB::table('till_users')->insert([
            ['id' => self::ALI, 'company_id' => $companyId, 'name' => 'Ali Khan'],
            ['id' => self::BEA, 'company_id' => $companyId, 'name' => 'Bea Jones'],
        ]);
    }

    /**
     * A completed sale with one line.
     *
     * @param  array<string, mixed>  $line
     */
    public static function sale(string $companyId, string $tag, string $branchId, string $userId, string $at, array $line = []): void
    {
        $saleId = self::id('S'.$tag);
        DB::table('sales')->insert([
            'id' => $saleId, 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => $branchId === TillFixtures::LEEDS ? TillFixtures::TILL_1 : TillFixtures::BRADFORD_TILL,
            'user_id' => $userId, 'number' => random_int(1, 999999), 'type' => 'sale', 'status' => 'completed', 'completed_at' => $at, 'total' => '10.00',
        ]);
        DB::table('sale_lines')->insert([
            'id' => self::id('L'.$tag), 'company_id' => $companyId, 'branch_id' => $branchId, 'sale_id' => $saleId, 'product_id' => self::id('PBEER'),
            'name' => 'Beer', 'qty' => '1.0000', 'is_age_restricted' => true, ...$line,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function refusal(string $companyId, string $tag, string $branchId, string $userId, string $at, array $row = []): void
    {
        DB::table('age_refusals')->insert([
            'id' => self::id('R'.$tag), 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => self::id('PBEER'), 'product_name' => 'Beer',
            'age_rule' => 'over18', 'user_id' => $userId, 'operator_name' => '', 'note' => '', 'at' => $at, ...$row,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function row(string $table, array $row): void
    {
        DB::table($table)->insert($row);
    }

    /** The beer product (over 18) of a business. */
    public static function beer(string $companyId): void
    {
        DB::table('products')->insert(['id' => self::id('PBEER'), 'company_id' => $companyId, 'name' => 'Beer', 'sku' => 'BEER1', 'age_rule' => 'over18']);
    }
}
