<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            // Unique across archived plans too, so a restored plan never clashes.
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            // Pounds, not pence (money rule). One licence = one till, so prices are per till.
            $table->decimal('price_per_till_monthly', 12, 2);
            $table->decimal('price_per_till_yearly', 12, 2);
            $table->char('currency', 3)->default('GBP');
            $table->unsignedSmallInteger('trial_days')->default(7);
            $table->unsignedSmallInteger('trial_grace_days')->default(3);
            $table->unsignedSmallInteger('grace_days')->default(7);
            // List of App\Domain\Plans\Enums\Feature values.
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
