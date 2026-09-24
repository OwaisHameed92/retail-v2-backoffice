<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The till pushes its Company, Branch and Register rows too (module 2.4). They land on module 1.2's tables:
     * sync updates only the till-owned fields (see definitions.php `tenancy`) and keeps its bookkeeping in till_*
     * columns so portal timestamps are untouched. The till's own counters are stored for display only.
     */
    public function up(): void
    {
        foreach (['companies', 'branches', 'registers'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if ($name === 'branches') {
                    $table->unsignedInteger('next_po_no')->nullable();
                }

                if ($name === 'registers') {
                    $table->unsignedInteger('next_sale_no')->nullable();
                    $table->unsignedInteger('next_refund_no')->nullable();
                }

                $table->unsignedBigInteger('till_row_version')->nullable();
                $table->dateTime('till_updated_at')->nullable();
                $table->dateTime('till_synced_at')->nullable();
                $table->unsignedBigInteger('till_sync_seq')->nullable();
                $table->json('till_extra')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['companies', 'branches', 'registers'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $columns = ['till_row_version', 'till_updated_at', 'till_synced_at', 'till_sync_seq', 'till_extra'];

                if ($name === 'branches') {
                    $columns[] = 'next_po_no';
                }

                if ($name === 'registers') {
                    array_push($columns, 'next_sale_no', 'next_refund_no');
                }

                $table->dropColumn($columns);
            });
        }
    }
};
