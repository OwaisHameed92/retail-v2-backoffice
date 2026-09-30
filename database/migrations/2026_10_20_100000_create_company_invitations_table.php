<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.1: invitations to a business's portal. The emailed link carries a one-time token; only its SHA-256 hash is
 * stored. Resending rotates the token (older links stop working); revoking or accepting closes it for good.
 * `branch_id` has no foreign key, like company_user.branch_id: a removed shop must never widen access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('role', 16);
            $table->char('branch_id', 26)->nullable();
            $table->char('token_hash', 64);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
    }
};
