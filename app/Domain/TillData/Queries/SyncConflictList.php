<?php

namespace App\Domain\TillData\Queries;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\SyncConflictResolution;
use App\Domain\TillData\Models\TillSyncConflict;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use App\Domain\TillData\Sync\Enums\ConflictResolution;
use App\Domain\TillData\Sync\Models\SyncConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The tenant portal's sync conflicts screen (module 2.9B), two lists of the current company:
 *
 * - `portal`: sync_conflicts, a shop's change the portal kept out (hubEditNewer, hubVersionNewer, branchEditNewer,
 *   immutableChange, tenancyDelete). Filters: status (open by default / resolved / all), kind, shop; search by what
 *   changed or its id.
 * - `shop`: the tills' own clashes (their `SyncConflict` rows with `hubChange`, contract §8): a pulled portal row met
 *   an unsent edit at the shop. Read only: the shop settles them. Filters: resolution (pending by default), shop.
 *
 * Runs inside the company scope (BelongsToCompany): another business's rows are never read.
 */
final class SyncConflictList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $tab = $request->query('tab') === 'shop' ? 'shop' : 'portal';
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $branches = Branch::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->get(['id', 'name']);
        $names = $branches->pluck('name', 'id')->all();
        $branch = $restricted ?? self::option($request, 'branch', array_keys($names));
        $conflicts = fn () => self::visible(SyncConflict::query());
        $clashes = fn () => self::visible(TillSyncConflict::query());

        $props = [
            'tab' => $tab,
            'search' => TableQuery::from($request)->search(),
            'counts' => [
                'open' => $conflicts()->open()->count(),
                'resolved' => $conflicts()->where('status', 'resolved')->count(),
                'shopPending' => $clashes()->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', 'pending'))->count(),
            ],
            'stats' => [
                'hubRows' => $conflicts()->open()->whereIn('kind', ['hubEditNewer', 'hubVersionNewer', 'branchEditNewer'])->count(),
                'historic' => $conflicts()->open()->whereIn('kind', ['immutableChange', 'tenancyDelete'])->count(),
                'oldestOpenAt' => self::iso($conflicts()->open()->min('created_at')),
            ],
            'options' => [
                'kinds' => array_map(fn (ConflictKind $k) => ['value' => $k->value, 'label' => $k->label()], ConflictKind::cases()),
                'branches' => $branches->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
                'resolutions' => array_map(fn (SyncConflictResolution $r) => ['value' => $r->value, 'label' => self::shopResolution($r->value)], SyncConflictResolution::cases()),
            ],
        ];

        if ($tab === 'shop') {
            $resolution = self::option($request, 'resolution', ['all', ...array_column(SyncConflictResolution::cases(), 'value')]) ?? 'pending';
            $query = $clashes()
                ->when($resolution === 'pending', fn ($q) => $q->where(fn ($w) => $w->whereNull('resolution')->orWhere('resolution', 'pending')))
                ->when(! in_array($resolution, ['pending', 'all'], true), fn ($q) => $q->where('resolution', $resolution))
                ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch));

            return [...$props, 'filters' => ['resolution' => $resolution, 'branch' => $branch], 'clashes' => TableQuery::from($request)
                ->searchable(['entity', 'entity_id', 'detail'])->sortable(['detected_at', 'entity'])->defaultSort('detected_at', 'desc')
                ->paginate($query, fn (TillSyncConflict $c) => [
                    'id' => $c->id, 'entity' => $c->entity, 'entityLabel' => self::entityLabel($c->entity), 'entityId' => $c->entity_id,
                    'subject' => self::subject(self::hubPayload($c->hub_change)),
                    'branch' => $names[$c->branch_id] ?? null, 'detail' => $c->detail, 'resolution' => $c->resolution->value ?? 'pending',
                    'resolutionLabel' => self::shopResolution($c->resolution->value ?? 'pending'), 'hasHubChange' => $c->hub_change !== null,
                    'detectedAt' => $c->detected_at->toIso8601ZuluString(), 'resolvedAt' => $c->resolved_at?->toIso8601ZuluString(),
                ])];
        }

        $status = self::option($request, 'status', ['open', 'resolved', 'all']) ?? 'open';
        $kind = self::option($request, 'kind', array_column(ConflictKind::cases(), 'value'));
        $query = $conflicts()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch));

        return [...$props, 'filters' => ['status' => $status, 'kind' => $kind, 'branch' => $branch], 'conflicts' => TableQuery::from($request)
            ->searchable(['entity', 'entity_id', 'detail'])->sortable(['created_at', 'entity', 'kind'])->defaultSort('created_at', 'desc')
            ->paginate($query, fn (SyncConflict $c) => self::row($c, $names))];
    }

    /**
     * Security review M1: a one-shop manager (module 3.3) sees only their shop's conflicts and clashes, never the
     * company-wide ones (no shop) or another shop's. Everyone else sees the whole company.
     *
     * @template TModel of SyncConflict|TillSyncConflict
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function visible(Builder $query): Builder
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $query->when($restricted !== null, fn (Builder $q) => $q->where('branch_id', $restricted));
    }

    /**
     * @param  array<string, string>  $branchNames
     * @return array<string, mixed>
     */
    public static function row(SyncConflict $c, array $branchNames): array
    {
        return [
            'id' => $c->id,
            'entity' => $c->entity,
            'entityLabel' => self::entityLabel($c->entity),
            'entityId' => $c->entity_id,
            'subject' => self::subject($c->incomingPayload()),
            'kind' => $c->kind->value,
            'kindLabel' => $c->kind->label(),
            'branch' => $c->branch_id === null ? null : ($branchNames[$c->branch_id] ?? null),
            'detail' => $c->detail,
            'status' => $c->status,
            'resolution' => $c->resolution,
            'resolutionLabel' => ConflictResolution::tryFrom((string) $c->resolution)?->label(),
            'incomingAt' => $c->incoming_at?->toIso8601ZuluString(),
            'receivedAt' => $c->created_at?->toIso8601ZuluString(),
            'resolvedAt' => $c->resolved_at?->toIso8601ZuluString(),
        ];
    }

    /** "PurchaseOrderLine" → "Purchase order line". */
    public static function entityLabel(string $entity): string
    {
        return Str::ucfirst(strtolower(Str::headline($entity)));
    }

    /**
     * What people call the row: its name, reference or key, if the shop's version has one.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function subject(?array $payload): ?string
    {
        foreach (['name', 'reference', 'receiptNumber', 'key', 'permissionKey', 'description', 'barcode'] as $member) {
            if (is_string($payload[$member] ?? null) && trim($payload[$member]) !== '') {
                return mb_substr(trim($payload[$member]), 0, 120);
            }
        }

        return null;
    }

    /**
     * The payload of a till clash's `hubChange` (the portal's envelope the till kept aside), if it has one.
     *
     * @return array<string, mixed>|null
     */
    public static function hubPayload(?string $hubChange): ?array
    {
        $envelope = $hubChange === null ? null : json_decode($hubChange, true);

        return is_array($envelope) && is_array($envelope['payload'] ?? null) ? $envelope['payload'] : null;
    }

    public static function shopResolution(string $value): string
    {
        return match ($value) {
            'hubWins' => 'Head office won',
            'branchWins' => 'Shop won',
            'ignored' => 'Ignored',
            default => 'Waiting at the shop',
        };
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->toIso8601ZuluString();
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function option(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
