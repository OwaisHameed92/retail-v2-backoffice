<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 5.1: AI conversations and their messages. A conversation belongs to one tenant user in one company,
     * or to one admin (company optional). Pruned after `ai.retention_days` of inactivity (messages cascade).
     */
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('admin_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('feature', 32);
            $table->string('title', 120)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'user_id']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('role', 16);
            // Wire-shape content blocks exactly as sent to / received from the API (after redaction).
            $table->json('content');
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();
            $table->string('model', 64)->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            // False for turns dropped from the context (a refused turn) so history stays valid.
            $table->boolean('in_context')->default(true);
            $table->timestamps();

            $table->unique(['conversation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
