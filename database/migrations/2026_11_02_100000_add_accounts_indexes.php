<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5.5 (accounts and VAT): indexes for the ledger sums and journal filters. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['company_id', 'branch_id', 'date'], 'journal_entries_company_branch_date_index');
            $table->index(['company_id', 'ref_type', 'date'], 'journal_entries_company_ref_type_date_index');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->index(['company_id', 'account_code'], 'journal_lines_company_account_code_index');
        });

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->index(['company_id', 'code'], 'ledger_accounts_company_code_index');
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->index(['company_id', 'invoice_date'], 'supplier_invoices_company_invoice_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex('supplier_invoices_company_invoice_date_index');
        });

        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->dropIndex('ledger_accounts_company_code_index');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('journal_lines_company_account_code_index');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_company_ref_type_date_index');
            $table->dropIndex('journal_entries_company_branch_date_index');
        });
    }
};
