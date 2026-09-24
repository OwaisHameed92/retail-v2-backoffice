<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Shared\Support\Ulid;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Data\SyncChange;

/**
 * Validates one envelope against schemas/sync-change.schema.json and the README's identity rules:
 * company must be the pushing company, a non-empty branchId must be the sending branch, a non-empty registerId
 * must be one of its tills, and the entity must be one the store knows.
 */
final class EnvelopeReader
{
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

        if (! EntityRegistry::has($entity)) {
            return $reject('entity.unknown', "Unknown entity \"{$entity}\".");
        }

        if ($raw['companyId'] !== $context->companyId) {
            return $reject('sync.wrong_company', 'The change belongs to another company.');
        }

        if ($raw['branchId'] !== '' && $raw['branchId'] !== $context->branchId) {
            return $reject('sync.wrong_branch', 'The change belongs to another branch than the one sending it.');
        }

        if ($raw['registerId'] !== '' && ! $context->ownsRegister($raw['registerId'])) {
            return $reject('sync.unknown_register', 'The change names a till that is not in the sending branch.');
        }

        return new SyncChange(
            $index, (int) $seq, $entity, $entityId, $raw['op'], (int) $version, $raw['companyId'],
            $raw['branchId'], $raw['registerId'], Values::dateTime($raw['at']), $raw['payload'], $key,
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

        if (! Ulid::isValid($entityId)) {
            $problems[] = 'entityId must be a ULID';
        }

        if (! in_array($raw['op'], ['I', 'U', 'D'], true)) {
            $problems[] = 'op must be I, U or D';
        }

        if (! Ulid::isValid($raw['companyId'])) {
            $problems[] = 'companyId must be a ULID';
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
}
