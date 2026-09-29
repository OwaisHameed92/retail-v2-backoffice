<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Queries\LicenceQuery;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin top-bar search (⌘K): up to 5 tenants (name, legal name, email, owner email) and 5 licences (last 4,
 * device id or name; a full key only when it arrives in a POST body). JSON only; `tenants.view` (route
 * middleware). Deleted rows are left out.
 *
 * A full licence key is a secret: it is never read from the query string (a GET with one finds nothing) and never
 * echoed back (the reply's `query` is then the masked key).
 */
class AdminSearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->isMethod('post') ? $request->request->get('q') : $request->query('q');
        $term = mb_substr(trim(is_string($raw) ? $raw : ''), 0, 100);
        $key = LicenceKey::tryParse($term);

        if ($key !== null) {
            return $this->byKey($request->isMethod('post') ? $key : null, LicenceKey::mask($key->last4()));
        }

        if (mb_strlen($term) < 2) {
            return response()->json(['query' => $term, 'tenants' => [], 'licences' => []]);
        }

        $like = '%'.$term.'%';
        $now = CarbonImmutable::now();

        $tenants = Company::query()
            ->where(fn (Builder $q) => $q
                ->where('companies.name', 'like', $like)
                ->orWhere('companies.legal_name', 'like', $like)
                ->orWhere('companies.email', 'like', $like)
                ->orWhereHas('users', fn (Builder $u) => $u->where('users.email', 'like', $like)->where('company_user.role', CompanyRole::Owner->value)))
            ->orderByRaw('case when companies.name like ? then 0 else 1 end', [$term.'%'])
            ->orderBy('companies.name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status->value,
                'detail' => $company->legal_name ?? $company->email,
                'url' => route('admin.tenants.show', $company),
            ]);

        $licenceQuery = LicenceQuery::admin()->whereNull('companies.deleted_at');
        LicenceQuery::search($licenceQuery, $term, includeBusiness: false);

        return response()->json(['query' => $term, 'tenants' => $tenants->values(), 'licences' => $this->licences($licenceQuery, $now)])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** A full key: looked up only when it came in the body; the reply shows only its masked form. */
    private function byKey(?LicenceKey $key, string $masked): JsonResponse
    {
        $licences = [];

        if ($key !== null) {
            $query = LicenceQuery::admin()->whereNull('companies.deleted_at');
            LicenceQuery::whereKey($query, $key);
            $licences = $this->licences($query, CarbonImmutable::now());
        }

        return response()->json(['query' => $masked, 'tenants' => [], 'licences' => $licences])
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * @param  Builder<Licence>  $query
     * @return list<array<string, mixed>>
     */
    private function licences(Builder $query, CarbonImmutable $now): array
    {
        return $query
            ->orderByDesc('licences.last_check_in_at')
            ->orderByDesc('licences.created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Licence $licence) => [
                'id' => $licence->id,
                'maskedKey' => $licence->maskedKey(),
                'status' => $licence->state($now)->status->value,
                'businessName' => $licence->company->name ?? 'Deleted business',
                'tillName' => $licence->register->name ?? 'Deleted till',
                'branchName' => $licence->branch->name ?? 'Deleted branch',
                'deviceName' => $licence->device_name,
                'url' => route('admin.licences.show', $licence->id),
            ])
            ->values()
            ->all();
    }
}
