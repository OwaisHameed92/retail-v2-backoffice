<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.5: the shops a till staff member works at. Portal-only: the contract's `User` has no shop, so every till
 * receives every staff member; this list drives the staff screen's shop filter. No foreign keys (a till row or a
 * shop may go away without touching this list).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_user_branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->string('till_user_id', 26);
            $table->string('branch_id', 26);
            $table->timestamps();

            $table->unique(['company_id', 'till_user_id', 'branch_id'], 'till_user_branches_unique');
            $table->index(['company_id', 'branch_id'], 'till_user_branches_company_branch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_user_branches');
    }
};
