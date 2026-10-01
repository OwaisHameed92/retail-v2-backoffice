<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6.3: the morning summary each portal user got for one trading day (the computed facts as sent, and the AI
 * narrative when one was written and passed the number check). The dashboard's "Yesterday at a glance" shows the
 * narrative again; a re-run of the 07:00 job reuses it instead of paying for a second call. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('morning_summaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->unsignedBigInteger('user_id');
            $table->date('trading_day');
            // 'all' (every shop) or a hash of the shop ids covered.
            $table->string('scope_key', 64);
            $table->string('facts_hash', 64);
            $table->json('facts');
            $table->text('narrative')->nullable();
            // written, rejected (a number not in the facts), refused, empty, failed, or an AiUnavailableReason value.
            $table->string('narrative_status', 32);
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'trading_day']);
            $table->index(['company_id', 'trading_day', 'facts_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('morning_summaries');
    }
};
