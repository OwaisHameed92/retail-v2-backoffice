<?php

namespace Tests\Feature\Reporting;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Module 4.8 test helpers: members with a role (and optionally one shop), the props of an Inertia response, a
 * figure of a report's summary and a table by key, and sums straight from the tables a report reads.
 */
final class ReportsHelpers
{
    public static function member(Company $company, CompanyRole $role, ?string $branchId = null): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $branchId]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public static function props(TestResponse $response): array
    {
        $response->assertOk();

        return json_decode((string) json_encode($response->viewData('page')['props']), true);
    }

    public static function figure(array $props, string $key): mixed
    {
        foreach ($props['result']['summary'] as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }

        throw new \RuntimeException("No figure {$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public static function table(array $props, string $key): array
    {
        foreach ($props['result']['tables'] as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }

        throw new \RuntimeException("No table {$key}");
    }

    /** Σ of a column of a table for a business (and shops), as a 2 dp string. */
    public static function sum(string $table, string $column, string $companyId, ?array $branchIds = null, int $scale = 2): string
    {
        $value = DB::table($table)->where('company_id', $companyId)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->selectRaw("SUM(ROUND({$column} * ".(10 ** $scale).')) as units')->value('units');

        return bcdiv((string) (int) round((float) $value), (string) (10 ** $scale), $scale);
    }
}
