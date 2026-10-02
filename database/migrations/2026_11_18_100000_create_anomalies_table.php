<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6.6: unusual activity found by the anomaly detectors, one row per finding. `dedupe_key` is the exact finding
 * (kind, shop, subject, day) and is unique per business; `group_key` (kind, shop, subject) lets a repeat within the
 * cool-down update the open row instead of raising a new one. Facts and links are JSON, money in them as strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('branch_id', 26)->nullable();
            $table->string('register_id', 26)->nullable();
            $table->string('kind', 40);
            $table->string('severity', 10);
            $table->boolean('staff_level')->default(false);
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_name', 255)->nullable();
            $table->string('dedupe_key', 191);
            $table->string('group_key', 191);
            $table->date('trading_day');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('title', 255);
            $table->text('summary');
            $table->json('facts');
            $table->json('links')->nullable();
            $table->decimal('score', 8, 2)->default(0);
            $table->unsignedInteger('occurrences')->default(1);
            $table->string('status', 20)->default('new');
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('status_by')->nullable();
            $table->dateTime('status_at')->nullable();
            $table->dateTime('detected_at');
            $table->dateTime('notified_at')->nullable();
            $table->text('explanation')->nullable();
            $table->dateTime('explained_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'dedupe_key'], 'anomalies_company_dedupe_unique');
            $table->index(['company_id', 'group_key'], 'anomalies_company_group_index');
            $table->index(['company_id', 'status', 'detected_at'], 'anomalies_company_status_index');
            $table->index(['company_id', 'branch_id', 'trading_day'], 'anomalies_company_branch_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
