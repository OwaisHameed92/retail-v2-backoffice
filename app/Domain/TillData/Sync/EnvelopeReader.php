<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Shared\Support\Ulid;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Data\SyncChange;

/**
 * Validates one envelope against schemas/sync-change.schema.json and the contract's identity rules (§5):
 * company must be the pushing company, a non-empty branchId must be the sending branch, a non-empty registerId
 * must be one of its tills. Any entity name is accepted (§18.8, §21.1): a `local` one is acknowledged without
 * storing, one the portal does not know yet is kept raw (UnknownEntityRows).
 */
final class EnvelopeReader
{
    /** Till-side screen/bookkeeping tables some till builds push with an empty companyId (EPOS asked, 2026-10-04). */
    public const TILL_BOOKKEEPING = ['EventSubscription', 'TopSellerTile'];

    private const INTEGER = '/^(0|[1-9]\d{0,17})$/';

    public function read(mixed $raw, int $index, SyncContext $context): SyncChange|Rejection
    {
        if (! is_array($raw)) {
            return new Rejection($index, null, '', 'change.invalid', 'The change is not a JSON object.');
        }

        $seq = $this->integer($raw['seq'] ?? null);
        $version = $this->integer($raw['version'] ?? null);
        $entity = is_string($raw['entity'] ?? null) ? $raw['entity'] : '';
        $entityId = is_string($raw['entityId'] ?? null) ? $raw['entityId'] : '';
        $key = is_string($raw['key'] ?? null) && $raw['key'] !== ''
            ? $raw['key']
            : $entity.':'.$entityId.':'.($version ?? '');
        $reject = fn (string $code, string $message) => new Rejection(
            $index, $seq, $key, $code, $message, $entity === '' ? null : $entity, Ulid::isValid($entityId) ? $entityId : null,
        );

        $problems = [];

        foreach (['seq', 'entity', 'entityId', 'op', 'version', 'companyId', 'branchId', 'registerId', 'at'] as $member) {
            if (! array_key_exists($member, $raw)) {
                $problems[] = "{$member} is missing";
            }
        }

        if (! array_key_exists('payload', $raw)) {
            $problems[] = 'payload is missing';
        }

        if ($problems === []) {
            $problems = $this->shapeProblems($raw, $seq, $version, $entity, $entityId);
        }

        if ($problems !== []) {
            return $reject('change.invalid', 'Invalid change: '.implode('; ', $problems).'.');
        }

        // A setting that never leaves the till (§10.3 deny-list) is acknowledged as skipped and never stored, so its
        // envelope ids do not matter. Till ≤ 0.1.29 sent per-user screen settings (grid.layout.*, help.tour_dismissed.*)
        // with the user's id as companyId; refusing them stalled the till's whole queue behind them (EPOS 2026-10-02).
        $localOnlySetting = $entity === 'Setting' && is_array($raw['payload'])
            && SettingSyncPolicy::isLocalOnly($raw['payload']['scope'] ?? null, $raw['payload']['key'] ?? null);

        if (! $localOnlySetting && ! self::isTillBookkeeping($raw) && $raw['companyId'] !== $context->companyId) {
            // Name the id we got (ids are not secret): an unmapped till company id is otherwise impossible to trace.
            return $reject('sync.wrong_company', 'The change belongs to another company ('.(is_string($raw['companyId']) ? $raw['companyId'] : 'not a string').').');
        }

        if (! $localOnlySetting && $raw['branchId'] !== '' && $raw['branchId'] !== $context->branchId) {
            return $reject('sync.wrong_branch', 'The change belongs to another branch than the one sending it.');
        }

        if ($raw['registerId'] !== '' && ! $context->ownsRegister($raw['registerId'])) {
            return $reject('sync.unknown_register', 'The change names a till that is not in the sending branch.');
        }

        return new SyncChange(
            $index, (int) $seq, $entity, $entityId, $raw['op'], (int) $version, self::isTillBookkeeping($raw) ? '' : $raw['companyId'],
            $raw['branchId'], $raw['registerId'], Values::dateTime($raw['at']), $raw['payload'], $key,
            isset($raw['baseVersion']) ? $this->integer($raw['baseVersion']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function shapeProblems(array $raw, ?int $seq, ?int $version, string $entity, string $entityId): array
    {
        $problems = [];

        if ($seq === null) {
            $problems[] = 'seq must be a whole number of 0 or more';
        }

        if ($version === null || $version < 1) {
            $problems[] = 'version must be a whole number of 1 or more';
        }

        if ($entity === '') {
            $problems[] = 'entity must be a non-empty string';
        }

        // Setting / RolePermission ids are 26 Crockford characters derived from their key, not ULIDs (§10.3).
        $keyed = EntityRegistry::has($entity) && EntityRegistry::get($entity)->isKeyed();

        if (! Ulid::isValid($entityId) && ! ($keyed && preg_match(SyncRowIds::PATTERN, $entityId) === 1)) {
            $problems[] = 'entityId must be a ULID';
        }

        if (! in_array($raw['op'], ['I', 'U', 'D'], true)) {
            $problems[] = 'op must be I, U or D';
        }

        if (! Ulid::isValid($raw['companyId']) && ! self::isTillBookkeeping($raw)) {
            $problems[] = 'companyId must be a ULID (got '.json_encode(mb_substr((string) $raw['companyId'], 0, 40)).')';
        }

        foreach (['branchId', 'registerId'] as $member) {
            if ($raw[$member] !== '' && ! Ulid::isValid($raw[$member])) {
                $problems[] = "{$member} must be a ULID or empty";
            }
        }

        try {
            Values::dateTime($raw['at']);
        } catch (InvalidValue $e) {
            $problems[] = 'at '.$e->getMessage();
        }

        if ($raw['payload'] !== null && ! is_array($raw['payload'])) {
            $problems[] = 'payload must be an object or null';
        }

        if (isset($raw['baseVersion']) && $this->integer($raw['baseVersion']) === null) {
            $problems[] = 'baseVersion must be a whole number of 0 or more';
        }

        if (array_key_exists('key', $raw) && ! is_string($raw['key'])) {
            $problems[] = 'key must be a string';
        }

        return $problems;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && preg_match(self::INTEGER, $value) === 1 ? (int) $value : null;
    }

    /**
     * Till 0.1.29–0.1.37 push their own EventSubscription bookkeeping (handler name + last event seq) with an empty
     * companyId. It is not business data: acknowledged as skipped and never stored (NeverStored), so it can no
     * longer stall the till's queue. Since till 0.1.38 the table is `local` (ownership.json, never pushed; a row
     * with our company is skipped as any local row) and from 0.1.42 no row goes up with a blank companyId, but older
     * tills still send these, so the exception stays.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function isTillBookkeeping(array $raw): bool
    {
        return in_array($raw['entity'] ?? null, self::TILL_BOOKKEEPING, true) && in_array($raw['companyId'] ?? null, ['', null], true);
    }
}
