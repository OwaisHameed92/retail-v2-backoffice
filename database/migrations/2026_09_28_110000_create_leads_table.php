<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trial requests (module 1.6): from the admin "Add lead" form and, later, the public trial form (1.10).
     * Not tenant data: leads belong to Switch & Save until "Approve 7-day trial" turns one into a company.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('business_name', 160);
            $table->string('contact_name', 120);
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            // Digits only, UK numbers in national form (07700900123), for duplicate checks.
            $table->string('phone_digits', 20)->nullable();
            $table->string('town', 80)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->unsignedSmallInteger('shops_count')->default(1);
            $table->unsignedSmallInteger('tills_count')->default(1);
            $table->string('business_type', 20)->default('convenience');
            $table->string('current_system', 160)->nullable();
            $table->text('message')->nullable();
            $table->string('source', 20)->default('website');
            $table->string('status', 20)->default('new');
            $table->foreignUlid('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            // One company per lead, and a company comes from one lead at most (NULLs repeat on SQLite and MySQL).
            $table->foreignUlid('company_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignUlid('converted_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->boolean('consent_marketing')->default(false);
            $table->json('utm')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_admin_id', 'status']);
            $table->index('follow_up_at');
            $table->index('email');
            $table->index('phone_digits');
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('lead_id')->constrained()->cascadeOnDelete();
            // Null for system notes (status changes by an action with no signed-in admin, e.g. the public form).
            $table->foreignUlid('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // note = typed by staff; the other kinds are written by the lead actions.
            $table->string('kind', 20)->default('note');
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('leads');
    }
};
