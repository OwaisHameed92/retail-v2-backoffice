<?php

namespace App\Domain\TillData\Queries;

use App\Domain\Shared\Support\Redactor;
use App\Domain\Sync\Support\PullPayload;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Models\TillSyncConflict;
use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\Enums\ConflictResolution;
use App\Domain\TillData\Sync\Models\SyncConflict;
use App\Domain\TillData\Sync\Values;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One conflict for the review page (module 2.9B): the facts, and a side-by-side of the row the portal kept against
 * the shop's version, member by member, in the till's terms. Secret and dropped members are never shown; PIN hashes
 * and RFID tags only say whether they differ. A shop clash (the till's own SyncConflict) shows the portal's row that
 * the till kept with it (`hubChange`).
 */
final class SyncConflictDetail
{
    private const MASKED = ['pinHash', 'rfid'];

    /**
     * @return array<string, mixed>
     */
    public static function conflict(SyncConflict $conflict): array
    {
        $def = EntityRegistry::has($conflict->entity) ? EntityRegistry::get($conflict->entity) : null;
        $stored = $def === null ? null : self::stored($def, $conflict->company_id, $conflict->entity_id);
        $names = Branch::query()->pluck('name', 'id')->all();
        $open = $conflict->status === 'open';

        return [
            'conflict' => [
                ...SyncConflictList::row($conflict, $names),
                'localVersion' => $conflict->local_version,
                'incomingVersion' => $conflict->incoming_version,
                'incomingSeq' => $conflict->incoming_seq,
                'resolutionNote' => $conflict->resolution_note,
                'resolvedBy' => $conflict->resolved_by === null ? null : User::query()->whereKey((int) $conflict->resolved_by)->value('name'),
                'rowExists' => $stored !== null,
            ],
            'fields' => $def === null ? [] : self::fields($def, $stored, $conflict->incomingPayload()),
            'resolutions' => $open ? array_map(fn (ConflictResolution $r) => [
                'value' => $r->value,
                'label' => $r->label(),
            ], ConflictResolution::allowedFor($conflict->kind)) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function clash(TillSyncConflict $clash): array
    {
        $envelope = $clash->hub_change === null ? null : json_decode($clash->hub_change, true);
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : null;
        $def = EntityRegistry::has($clash->entity) ? EntityRegistry::get($clash->entity) : null;

        return [
            'clash' => [
                'id' => $clash->id,
                'entity' => $clash->entity,
                'entityLabel' => SyncConflictList::entityLabel($clash->entity),
                'entityId' => $clash->entity_id,
                'subject' => SyncConflictList::subject($payload),
                'branch' => $clash->branch_id === null ? null : Branch::query()->whereKey($clash->branch_id)->value('name'),
                'detail' => $clash->detail,
                'ownership' => $clash->ownership?->value,
                'hubVersion' => $clash->hub_version,
                'branchVersion' => $clash->branch_version,
                'resolution' => $clash->resolution->value ?? 'pending',
                'resolutionLabel' => SyncConflictList::shopResolution($clash->resolution->value ?? 'pending'),
                'detectedAt' => $clash->detected_at->toIso8601ZuluString(),
                'resolvedAt' => $clash->resolved_at?->toIso8601ZuluString(),
            ],
            'hubChange' => $envelope === null ? null : [
                'op' => is_string($envelope['op'] ?? null) ? $envelope['op'] : null,
                'version' => is_int($envelope['version'] ?? null) ? $envelope['version'] : null,
                'at' => is_string($envelope['at'] ?? null) ? $envelope['at'] : null,
            ],
            'fields' => $def === null || $payload === null ? [] : self::fields($def, self::stored($def, $clash->company_id, $clash->entity_id), $payload),
        ];
    }

    /**
     * Member by member: the portal's stored value and the other side's, as text.
     *
     * @param  array<string, mixed>|null  $stored  column => value
     * @param  array<string, mixed>|null  $incoming  the till-shaped payload
     * @return list<array{key: string, label: string, portal: string|null, till: string|null, changed: bool}>
     */
    private static function fields(EntityDefinition $def, ?array $stored, ?array $incoming): array
    {
        $rows = [];

        foreach ($def->fields as $name => $field) {
            if ($field->type === 'secret' || in_array($name, $def->dropped, true) || Redactor::isSecretKey($name)) {
                continue;
            }

            $portal = $stored === null ? null : self::text(PullPayload::value($field->type, $stored[$field->column] ?? null));
            $till = $incoming === null || ! array_key_exists($name, $incoming) ? null : self::text(self::normalise($def, $name, $incoming[$name]));
            $masked = in_array($name, self::MASKED, true);

            $rows[] = [
                'key' => $name,
                'label' => Str::ucfirst(strtolower(Str::headline($name))),
                'portal' => $masked && $portal !== null ? 'Hidden' : $portal,
                'till' => $masked && $till !== null ? 'Hidden' : $till,
                'changed' => $incoming !== null && array_key_exists($name, $incoming) && $portal !== $till,
            ];
        }

        return $rows;
    }

    private static function normalise(EntityDefinition $def, string $name, mixed $value): mixed
    {
        try {
            return PullPayload::value($def->fields[$name]->type, Values::toColumn($def->fields[$name], $value));
        } catch (Throwable) {
            return $value;
        }
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * The row as the portal holds it now, keyed by the entity's columns (tenancy rows: their till fields).
     *
     * @return array<string, mixed>|null
     */
    private static function stored(EntityDefinition $def, string $companyId, string $id): ?array
    {
        $row = DB::table($def->table)->where($def->entity === 'Company' ? 'id' : 'company_id', $companyId)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        foreach ($def->tillFields as $field => $column) {
            $row[$def->fields[$field]->column] = $row[$column] ?? null;
        }

        return $row;
    }
}
