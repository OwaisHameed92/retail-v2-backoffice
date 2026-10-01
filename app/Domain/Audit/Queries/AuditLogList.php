<?php

namespace App\Domain\Audit\Queries;

use App\Domain\Admin\Models\Admin;
use App\Domain\Audit\Data\AuditFilters;
use App\Domain\Audit\Support\AuditLabels;
use App\Domain\Audit\Support\AuditPresenter;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Props for the audit log screens: one keyset page of entries, and the filter options (actors, actions, record types)
 * drawn from the same scope. `$companyId` = a tenant's own business; null = the admin view.
 */
final class AuditLogList
{
    public const PER_PAGE = [25, 50, 100];

    private const OPTION_LIMIT = 300;

    /** @return array<string, mixed> */
    public static function for(Request $request, AuditFilters $f, ?string $companyId): array
    {
        $perPage = in_array((int) $request->query('perPage'), self::PER_PAGE, true) ? (int) $request->query('perPage') : 50;
        $page = AuditSearch::page(
            AuditSearch::query($f, $companyId),
            self::cursor($request, 'after'),
            self::cursor($request, 'before'),
            $perPage,
        );

        return [
            'entries' => [
                'data' => (new AuditPresenter($companyId !== null))->rows($page['rows']),
                'older' => $page['older'],
                'newer' => $page['newer'],
                'perPage' => $perPage,
            ],
            'filters' => $f->toArray(),
            'options' => [
                'actions' => self::actions($companyId),
                'subjectTypes' => self::subjectTypes($companyId),
                'actors' => $companyId === null ? self::admins() : self::members($companyId),
                'company' => $companyId === null && $f->company !== null
                    ? Company::withTrashed()->whereKey($f->company)->first(['id', 'name'])?->only(['id', 'name'])
                    : null,
            ],
        ];
    }

    /**
     * Action groups ("licence.*") followed by every action, both with labels.
     *
     * @return list<array{value: string, label: string, group: bool}>
     */
    private static function actions(?string $companyId): array
    {
        $actions = self::scoped($companyId)->distinct()->orderBy('action')->limit(self::OPTION_LIMIT)->pluck('action')->map(fn ($a) => (string) $a);
        $groups = $actions->map(fn (string $a) => AuditLabels::group($a))->unique()->sort()->values();

        return [
            ...$groups->map(fn (string $g) => ['value' => $g.'.*', 'label' => AuditLabels::action($g).' (all)', 'group' => true])->all(),
            ...$actions->map(fn (string $a) => ['value' => $a, 'label' => AuditLabels::action($a), 'group' => false])->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function subjectTypes(?string $companyId): array
    {
        return self::scoped($companyId)->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->limit(self::OPTION_LIMIT)
            ->pluck('subject_type')
            ->map(fn ($type) => ['value' => (string) $type, 'label' => AuditLabels::type((string) $type)])
            ->sortBy('label')->values()->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function admins(): array
    {
        return Admin::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Admin $a) => ['value' => 'admin:'.$a->id, 'label' => $a->name])->values()->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function members(string $companyId): array
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => 'user:'.$u->id, 'label' => $u->name])->values()->all();
    }

    /**
     * @return Builder<AuditLog>
     */
    private static function scoped(?string $companyId)
    {
        return AuditLog::query()->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));
    }

    private static function cursor(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && strlen($value) <= 60 ? $value : null;
    }
}
