<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 5.1: one row per model call, for budgets and billing. No foreign keys on purpose (like audit_logs):
     * usage must outlive conversations, users and admins. Cost in pounds with 6 dp (a call costs fractions of a penny).
     */
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->ulid('admin_id')->nullable()->index();
            $table->ulid('conversation_id')->nullable()->index();
            $table->string('feature', 32);
            $table->string('model', 64);
            $table->string('status', 16);
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_gbp', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
