<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per outgoing email (module 1.7). Never stores the body or any secret: licence keys and
 * password links only live in the email itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // No FK on purpose: the log must outlive the company row and never block a delete.
            $table->string('company_id', 26)->nullable()->index();
            $table->string('to', 320);
            $table->string('mailable');
            $table->string('template', 64)->index();
            $table->string('subject', 255)->nullable();
            $table->string('status', 16)->index();
            $table->text('error')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
