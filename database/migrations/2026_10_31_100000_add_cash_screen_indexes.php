<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.4 (cash and Z): the screens read shifts by open time, and banking, safe counts, card settlements and day
 * locks by day, across every shop of a business (the till's indexes lead with the shop or have no date). Additive.
 */
return new class extends Migration
{
    /** @var array<string, array{string, list<string>}> table → [index name, columns] */
    private const INDEXES = [
        'shifts' => ['shifts_company_id_opened_at_index', ['company_id', 'opened_at']],
        'cash_office_bankings' => ['cash_office_bankings_company_id_prepared_at_index', ['company_id', 'prepared_at']],
        'cash_office_reconciliations' => ['cash_office_reconciliations_company_id_trading_date_index', ['company_id', 'trading_date']],
        'card_settlements' => ['card_settlements_company_id_trading_date_index', ['company_id', 'trading_date']],
        'day_locks' => ['day_locks_company_id_trading_date_index', ['company_id', 'trading_date']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$name, $columns]) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$name]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }
};
