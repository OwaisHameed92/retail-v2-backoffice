<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\Enums\ConflictResolution;
use App\Domain\TillData\Sync\InvalidValue;
use App\Domain\TillData\Sync\Models\SyncConflict;
use App\Domain\TillData\Sync\OwnershipRules;
use App\Domain\TillData\Sync\Values;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settles one open sync_conflicts row (module 2.9B, contract §8, §19.3). The portal's row already won (it is stored
 * and was sent to every till again when the conflict was recorded); a person confirms or takes the shop's version:
 *
 * - `keepPortal` (hub rows): nothing changes; the conflict is closed.
 * - `useTill` (hub rows): the shop's payload, as it was pushed, is written as a portal edit (HubOwnedRow): stamped
 *   for the pull and sent to every till, the shop's own included (identical content is a no-op there, §19.2).
 *   Ledger-derived columns (a customer's balance and points) are never taken from it (§10.1).
 * - `acknowledged` (a historic record, a shop's Company / Branch / till delete): nothing can be applied.
 *
 * Recorded in the audit log. Runs as the conflict's company.
 */
final class ResolveSyncConflict
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(SyncConflict $conflict, ConflictResolution $resolution, ?Model $actor = null, ?string $note = null): SyncConflict
    {
        if ($conflict->status !== 'open') {
            throw ValidationException::withMessages(['conflict' => 'This conflict has already been resolved.']);
        }

        if (! in_array($resolution, ConflictResolution::allowedFor($conflict->kind), true)) {
            throw ValidationException::withMessages(['resolution' => 'That choice does not apply to this kind of conflict.']);
        }

        $company = Company::query()->findOrFail($conflict->company_id);

        return $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($conflict, $resolution, $actor, $note): SyncConflict {
            $locked = SyncConflict::query()->lockForUpdate()->findOrFail($conflict->id);

            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['conflict' => 'This conflict has already been resolved.']);
            }

            if ($resolution === ConflictResolution::UseTill) {
                $this->applyTillVersion($locked);
            }

            $locked->forceFill([
                'status' => 'resolved',
                'resolution' => $resolution->value,
                'resolved_at' => CarbonImmutable::now('UTC'),
                'resolved_by' => $actor === null ? null : (string) $actor->getKey(),
                'resolution_note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
            ])->save();

            $this->audit->handle('sync_conflict.resolved', $locked, ['status' => 'open'], ['status' => 'resolved', 'resolution' => $resolution->value], [
                'entity' => $locked->entity, 'entity_id' => $locked->entity_id, 'kind' => $locked->kind->value,
            ], $actor, $locked->company_id);

            return $locked;
        }));
    }

    /**
     * @throws ValidationException
     */
    private function applyTillVersion(SyncConflict $conflict): void
    {
        $payload = $conflict->incomingPayload();
        $def = EntityRegistry::has($conflict->entity) ? EntityRegistry::get($conflict->entity) : null;

        if ($payload === null || $def === null || ! $def->isHubOwned() || $def->tenancy) {
            throw ValidationException::withMessages(['resolution' => 'The shop\'s version cannot be applied: nothing usable was received.']);
        }

        /** @var class-string<Model> $class */
        $class = $def->model;
        // A soft-deleted row too: the shop's version may bring it back (its `deletedAt`).
        $row = $class::query()->withoutGlobalScope(SoftDeletingScope::class)->find($conflict->entity_id);

        if ($row === null) {
            throw ValidationException::withMessages(['resolution' => 'The row no longer exists on the portal.']);
        }

        try {
            $row->forceFill($this->columns($def, $payload))->save();
        } catch (InvalidValue $e) {
            throw ValidationException::withMessages(['resolution' => 'The shop\'s version cannot be applied: '.$e->getMessage()]);
        }
    }

    /**
     * The payload's stored members as columns (secret, dropped and ledger-derived members left out).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function columns(EntityDefinition $def, array $payload): array
    {
        $columns = [];
        $derived = OwnershipRules::derivedColumns($def->entity);

        foreach ($def->fields as $name => $field) {
            if (! array_key_exists($name, $payload) || $field->type === 'secret' || in_array($field->column, $derived, true) || in_array($name, $def->dropped, true)) {
                continue;
            }

            $value = Values::toColumn($field, $payload[$name]);
            // The model casts JSON members itself: give it the decoded value, not the encoded text.
            $columns[$field->column] = $field->type === 'json' && $value !== null ? $payload[$name] : $value;
        }

        if (array_key_exists('deletedAt', $payload)) {
            $columns['deleted_at'] = $payload['deletedAt'] === null ? null : Values::dateTime($payload['deletedAt']);
        }

        return $columns;
    }
}
