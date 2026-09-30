<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.3 (branch stock transfers): the list reads transfers by the day they were raised, and the discrepancy report
 * reads receipts by the day they were received, across every shop of a business. Additive.
 */
return new class extends Migration
{
    /** @var array<string, array{string, list<string>}> table → [index name, columns] */
    private const INDEXES = [
        'stock_transfers' => ['stock_transfers_company_id_requested_at_index', ['company_id', 'requested_at']],
        'stock_transfer_receipts' => ['stock_transfer_receipts_company_id_received_at_index', ['company_id', 'received_at']],
        'stock_transfer_receipt_lines' => ['stock_transfer_receipt_lines_company_transfer_index', ['company_id', 'transfer_id']],
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
