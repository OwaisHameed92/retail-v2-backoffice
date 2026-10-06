<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pakistan plan P5 (manual collection), additive only: when each payment reminder email went for an invoice paid by
 * hand (before the due date, on it, and after it), so billing:run sends each one once. Null on every existing row and
 * never set on a Direct Debit (GB) instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('due_soon_reminded_at')->nullable();
            $table->timestamp('due_today_reminded_at')->nullable();
            $table->timestamp('overdue_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['due_soon_reminded_at', 'due_today_reminded_at', 'overdue_reminded_at']);
        });
    }
};
