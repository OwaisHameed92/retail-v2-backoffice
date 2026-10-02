<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI security review: each proposed change carries an HMAC binding it to its company, proposer, tool, input and
 * expiry (ProposalSignature). ConfirmAiAction runs nothing whose signature does not match. Additive; rows proposed
 * before this have none and can no longer be confirmed (they expire within minutes anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_pending_actions', function (Blueprint $table) {
            $table->string('signature', 64)->nullable()->after('preview');
        });
    }

    public function down(): void
    {
        Schema::table('ai_pending_actions', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
