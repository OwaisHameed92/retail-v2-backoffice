<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The till's 11 feature names (contract v1.3.3 ANSWERS §6), in the Feature enum's order. */
    private const TILL_FEATURES = [
        'loyalty', 'promotions', 'purchasing', 'accounts', 'multi_branch', 'second_screen', 'label_printing',
        'cloud_sync', 'assist', 'assist_invoice_scan', 'assist_questions',
    ];

    /** Our old names → the till's; the ones the till has no feature for are dropped. */
    private const OLD_NAMES = [
        'purchasing' => ['purchasing'],
        'accounts' => ['accounts'],
        'multiBranch' => ['multi_branch'],
        'aiAssistant' => ['assist', 'assist_questions'],
        'aiInsights' => ['assist', 'assist_invoice_scan'],
    ];

    /**
     * Module 2.1 (contract v1.3.3 answers 1–2):
     *
     * - `id_map`: the till keeps its own company/branch/register ids; we map them to ours (adopt / alias) and
     *   translate at the edge. One row per till id (unique kind + till_id); several till ids may map to one of ours.
     * - `sync_keys`: per-branch sync API keys (hash + last 4 only), replaced keys keep working 7 days.
     * - Feature values become the till's names in plans, licences and branch licence settings.
     */
    public function up(): void
    {
        Schema::create('id_map', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->char('till_id', 26);
            $table->char('portal_id', 26);
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->string('action', 10);
            $table->string('install_id', 26)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['kind', 'till_id']);
            $table->index(['company_id', 'kind', 'portal_id']);
        });

        Schema::create('sync_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('branch_id')->constrained()->cascadeOnDelete();
            $table->char('key_hash', 64)->unique();
            $table->char('key_last4', 4);
            $table->string('source', 10);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('delivered_install_id', 26)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('rotate_requested_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'revoked_at', 'replaced_at']);
        });

        $this->renameFeatures();
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_keys');
        Schema::dropIfExists('id_map');
    }

    private function renameFeatures(): void
    {
        foreach ([['plans', 'features'], ['licences', 'features'], ['branches', 'licence_features']] as [$table, $column]) {
            DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])->chunk(500, function ($rows) use ($table, $column) {
                foreach ($rows as $row) {
                    $old = json_decode((string) $row->{$column}, true);

                    if (! is_array($old)) {
                        continue;
                    }

                    $new = $this->rename($old);

                    if ($new !== $old) {
                        DB::table($table)->where('id', $row->id)->update([$column => json_encode($new)]);
                    }
                }
            });
        }
    }

    /**
     * @param  array<mixed>  $old
     * @return list<string>
     */
    private function rename(array $old): array
    {
        $wanted = [];

        foreach ($old as $value) {
            foreach (is_string($value) ? (self::OLD_NAMES[$value] ?? [$value]) : [] as $name) {
                $wanted[$name] = true;
            }
        }

        return array_values(array_filter(self::TILL_FEATURES, fn (string $name) => isset($wanted[$name])));
    }
};
