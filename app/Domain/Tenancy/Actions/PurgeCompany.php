<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Removes one business for good (`tenant:purge`, and `demo:billing --fresh` for demo businesses): the company row
 * and every row that belongs to it — every table with a `company_id` (branches, tills, licences, sync keys, id map,
 * billing account, invoices, payments, GoCardless rows, sales, stock, audit rows…), the rows hanging off those
 * without one (AI messages, lead notes, local key refusals), audit rows about the company, the logins that belong to
 * no other business (with their sessions and reset tokens) and its files (imports, exports). One transaction;
 * files go after it commits. Other businesses are never touched: every delete is filtered on this company's id.
 *
 * A business that has traded (an activated licence or any sale) is refused unless forced.
 */
class PurgeCompany
{
    /** Files kept per business on the local disk. */
    public const FILE_DIRECTORIES = ['invoice-imports', 'product-imports', 'sales-exports'];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * What would be removed: rows per table (only tables with rows), plus "users" and "files".
     *
     * @return array<string, int>
     */
    public function plan(Company $company): array
    {
        $counts = [];

        foreach ($this->queries($company) as $table => $query) {
            $count = $query->count();

            if ($count > 0) {
                $counts[$table] = ($counts[$table] ?? 0) + $count;
            }
        }

        $users = count($this->ownUserIds($company));
        $files = count($this->files($company));

        return [...$counts, 'companies' => 1, ...($users > 0 ? ['users (only in this business)' => $users] : []), ...($files > 0 ? ['files' => $files] : [])];
    }

    /**
     * Why removing it needs --force: it has traded.
     *
     * @return list<string>
     */
    public function blockers(Company $company): array
    {
        $blockers = [];
        $activated = DB::table('licences')->where('company_id', $company->id)->whereNotNull('activated_at')->count();
        $sales = Schema::hasTable('sales') ? DB::table('sales')->where('company_id', $company->id)->count() : 0;

        if ($activated > 0) {
            $blockers[] = "{$activated} activated licence".($activated === 1 ? '' : 's');
        }

        if ($sales > 0) {
            $blockers[] = number_format($sales).' sale'.($sales === 1 ? '' : 's');
        }

        return $blockers;
    }

    /**
     * @return array<string, int> rows removed per table
     *
     * @throws ValidationException when it has traded and `$force` is false
     */
    public function handle(Company $company, bool $force = false): array
    {
        if (! $force && ($blockers = $this->blockers($company)) !== []) {
            throw ValidationException::withMessages(['company' => "{$company->name} has traded (".implode(', ', $blockers).'). Use --force to remove it anyway.']);
        }

        $files = $this->files($company);

        $removed = DB::transaction(function () use ($company) {
            $removed = [];
            $userIds = $this->ownUserIds($company);
            $pending = $this->queries($company);

            // Child rows first: retry what a foreign key refused until every table is empty (or nothing moves).
            for ($pass = 0; $pending !== [] && $pass <= count($pending) + 1; $pass++) {
                foreach ($pending as $table => $query) {
                    try {
                        $removed[$table] = ($removed[$table] ?? 0) + $query->delete();
                        unset($pending[$table]);
                    } catch (QueryException) {
                        // A row elsewhere still points at it: try again after the others.
                    }
                }
            }

            if ($pending !== []) {
                throw new RuntimeException('Could not remove rows from: '.implode(', ', array_keys($pending)));
            }

            if ($userIds !== []) {
                $emails = DB::table('users')->whereIn('id', $userIds)->pluck('email')->all();
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
                DB::table('password_setup_tokens')->whereIn('email', $emails)->delete();
                $removed['users'] = DB::table('users')->whereIn('id', $userIds)->delete();
            }

            $removed['companies'] = DB::table('companies')->where('id', $company->id)->delete();

            return array_filter($removed);
        });

        DB::afterCommit(function () use ($files) {
            Storage::disk('local')->delete($files);

            foreach ($files === [] ? [] : array_unique(array_map('dirname', $files)) as $directory) {
                Storage::disk('local')->deleteDirectory($directory);
            }
        });

        $this->audit->handle('company.purged', null, ['id' => $company->id, 'name' => $company->name], null, [
            'company_id' => $company->id,
            'name' => $company->name,
            'demo' => (bool) $company->is_demo,
            'rows' => array_sum($removed),
        ]);

        return $removed;
    }

    /**
     * One delete query per table, children before parents where we know them.
     *
     * @return array<string, Builder>
     */
    private function queries(Company $company): array
    {
        $id = $company->id;
        $queries = [];

        // Rows without a company_id that belong to rows that have one.
        $queries['ai_messages'] = DB::table('ai_messages')->whereIn('conversation_id', DB::table('ai_conversations')->select('id')->where('company_id', $id));
        $queries['lead_notes'] = DB::table('lead_notes')->whereIn('lead_id', DB::table('leads')->select('id')->where('company_id', $id));
        $queries['local_licence_key_refusals'] = DB::table('local_licence_key_refusals')->whereIn('local_licence_key_id', DB::table('local_licence_keys')->select('id')
            ->where(fn ($q) => $q->where('company_id', $id)->orWhere('claimed_company_id', $id)));
        $queries['audit_logs (about the business)'] = DB::table('audit_logs')->where('subject_id', $id)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', '!=', $id));
        $queries['local_licence_keys (claimed)'] = DB::table('local_licence_keys')->where('claimed_company_id', $id)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', '!=', $id));

        foreach ($this->companyTables() as $table) {
            $queries[$table] = DB::table($table)->where('company_id', $id);
        }

        return $queries;
    }

    /**
     * Every table with a company_id column (but `companies` itself), deepest tables first.
     *
     * @return list<string>
     */
    private function companyTables(): array
    {
        $tables = array_column(Schema::getTables(Schema::getCurrentSchemaName()), 'name');
        $tables = array_values(array_filter($tables, fn (string $table) => $table !== 'companies' && Schema::hasColumn($table, 'company_id')));
        $parents = ['companies', 'branches', 'registers', 'licences', 'invoices', 'payments', 'billing_accounts', 'products', 'customers', 'sales', 'leads', 'ai_conversations'];
        usort($tables, fn (string $a, string $b) => [(int) in_array($a, $parents, true), $a] <=> [(int) in_array($b, $parents, true), $b]);

        return $tables;
    }

    /**
     * Logins that belong to this business only.
     *
     * @return list<string>
     */
    private function ownUserIds(Company $company): array
    {
        return DB::table('company_user')->where('company_id', $company->id)
            ->whereNotIn('user_id', DB::table('company_user')->select('user_id')->where('company_id', '!=', $company->id))
            ->pluck('user_id')->map(fn (mixed $id) => (string) $id)->unique()->values()->all();
    }

    /**
     * @return list<string>
     */
    private function files(Company $company): array
    {
        $disk = Storage::disk('local');
        $files = [];

        foreach (self::FILE_DIRECTORIES as $directory) {
            $files = [...$files, ...$disk->allFiles($directory.'/'.$company->id)];
        }

        return $files;
    }
}
