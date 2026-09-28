<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 1.11, the licence form of contract v1.3.1 (§17.2 `company` block, §17.16):
     *
     * - companies: the shop details a key carries that we did not hold (business type, town, postcode, owner's
     *   name, receipt footer) and the branch limits (`multi_branch`, `max_branches` → `limits.branches`);
     * - branches: town, postcode, receipt footer (override the company's) and the branch's licence settings: tills
     *   allowed (`max_registers` → `maxRegisters`), kind, length (+ optional start) and features;
     * - licences: `activate_by`, after which an unused key answers 410 key.expired.
     *
     * Existing rows keep working: tills allowed = the branch's active tills, branches allowed = the company's
     * active branches (multi-branch on when it already runs more than one), unused keys get 30 days from now.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('business_type', 40)->nullable()->after('contact_name');
            $table->string('town', 80)->nullable()->after('address');
            $table->string('postcode', 10)->nullable()->after('town');
            $table->string('owner_name', 80)->nullable()->after('contact_name');
            $table->string('receipt_footer', 200)->nullable()->after('owner_name');
            $table->boolean('multi_branch')->default(false)->after('plan_id');
            $table->unsignedSmallInteger('max_branches')->default(1)->after('multi_branch');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('town', 80)->nullable()->after('address');
            $table->string('postcode', 10)->nullable()->after('town');
            $table->string('receipt_footer', 200)->nullable()->after('vat_number');
            $table->unsignedSmallInteger('max_registers')->default(1)->after('is_active');
            $table->string('licence_kind', 10)->default('trial')->after('max_registers');
            $table->unsignedSmallInteger('licence_length')->nullable()->after('licence_kind');
            $table->string('licence_length_unit', 10)->nullable()->after('licence_length');
            $table->timestamp('licence_valid_from')->nullable()->after('licence_length_unit');
            $table->json('licence_features')->nullable()->after('licence_valid_from');
        });

        Schema::table('licences', function (Blueprint $table) {
            $table->timestamp('activate_by')->nullable()->after('activated_at');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn('activate_by');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['town', 'postcode', 'receipt_footer', 'max_registers', 'licence_kind', 'licence_length', 'licence_length_unit', 'licence_valid_from', 'licence_features']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'town', 'postcode', 'owner_name', 'receipt_footer', 'multi_branch', 'max_branches']);
        });
    }

    private function backfill(): void
    {
        $tills = DB::table('registers')->where('is_active', true)->whereNull('deleted_at')
            ->selectRaw('branch_id, count(*) as total')->groupBy('branch_id')->pluck('total', 'branch_id');

        foreach ($tills as $branchId => $total) {
            DB::table('branches')->where('id', $branchId)->update(['max_registers' => max(1, min(999, (int) $total))]);
        }

        $branches = DB::table('branches')->where('is_active', true)->whereNull('deleted_at')
            ->selectRaw('company_id, count(*) as total')->groupBy('company_id')->pluck('total', 'company_id');

        foreach ($branches as $companyId => $total) {
            DB::table('companies')->where('id', $companyId)->update(['max_branches' => max(1, (int) $total), 'multi_branch' => (int) $total > 1]);
        }

        DB::table('licences')->whereNull('activated_at')->where('status', '!=', 'revoked')
            ->update(['activate_by' => CarbonImmutable::now()->addDays(30)->utc()->format('Y-m-d H:i:s')]);
    }
};
