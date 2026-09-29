<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ANSWERS-2026-09-29-b A.3: a shop's own hub-owned row (NewsTitle, BranchPrice) moved to another shop. One row
     * per (row, shop it left); the pull sends that shop a `D` (BranchDepartures). `from_branch_id` = the row's
     * branch before the move ('' = every shop), used as the envelope's branchId. No foreign keys, like every sync table.
     */
    public function up(): void
    {
        Schema::create('sync_branch_departures', function (Blueprint $table) {
            $table->id();
            $table->string('company_id', 26);
            $table->string('entity', 64);
            $table->string('entity_id', 26);
            $table->string('branch_id', 26);
            $table->string('from_branch_id', 26)->default('');
            $table->unsignedBigInteger('hub_version')->nullable();
            $table->dateTime('departed_at');

            $table->unique(['entity', 'entity_id', 'branch_id'], 'sync_branch_departures_row_branch_unique');
            $table->index(['company_id', 'branch_id', 'hub_version'], 'sync_branch_departures_feed_index');
            $table->index(['company_id', 'hub_version'], 'sync_branch_departures_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_branch_departures');
    }
};
