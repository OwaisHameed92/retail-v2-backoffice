<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 5.1: changes proposed by AI write tools. Nothing changes until a person confirms (ConfirmAiAction).
     */
    public function up(): void
    {
        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('tool', 64);
            $table->json('input');
            $table->string('preview', 1000);
            $table->string('status', 16)->index();
            $table->json('result')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
    }
};
