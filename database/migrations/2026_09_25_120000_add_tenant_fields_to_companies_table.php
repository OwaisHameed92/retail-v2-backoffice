<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('email');
            $table->text('notes')->nullable()->after('status');
            $table->timestamp('trial_ends_at')->nullable()->after('notes');
            $table->timestamp('activated_at')->nullable()->after('trial_ends_at');
            $table->timestamp('suspended_at')->nullable()->after('activated_at');
            $table->string('suspended_from_status', 16)->nullable()->after('suspended_at');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_from_status');
            $table->timestamp('cancelled_at')->nullable()->after('suspension_reason');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'contact_name',
                'notes',
                'trial_ends_at',
                'activated_at',
                'suspended_at',
                'suspended_from_status',
                'suspension_reason',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
