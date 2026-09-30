<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4.6: a sales CSV export too big to stream at once is built by a queued job into a private file; this row is
 * its status and file for the user who asked (downloadable by them only, for 7 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('company_id', 26);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20);
            $table->json('filters');
            $table->string('path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_exports');
    }
};
