<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.2 (purchasing screens): indexes for the links the order, delivery, invoice and credit note pages follow
 * (deliveries of an order, invoices of a delivery, credits of an invoice, accruals of a rebate agreement). Additive.
 */
return new class extends Migration
{
    private const INDEXES = [
        'goods_receipts' => ['purchase_order_id', 'goods_receipts_company_id_purchase_order_id_index'],
        'supplier_invoices' => ['goods_receipt_id', 'supplier_invoices_company_id_goods_receipt_id_index'],
        'supplier_credit_notes' => ['linked_invoice_id', 'supplier_credit_notes_company_id_linked_invoice_id_index'],
        'rebate_accruals' => ['agreement_id', 'rebate_accruals_company_id_agreement_id_index'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$column, $name]) {
            Schema::table($table, fn (Blueprint $t) => $t->index(['company_id', $column], $name));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [, $name]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }
};
