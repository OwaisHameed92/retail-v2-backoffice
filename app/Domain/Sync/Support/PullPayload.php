<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\Redactor;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\TillData\Registry\EntityDefinition;

/**
 * One stored hub-owned row → one pull envelope in the till's shape (module 2.5, contract §5, §6, §11): camelCase
 * members, every stored member written (nulls too), money as numbers with at most 2 dp (costs, quantities 4),
 * enums as their strings, date-times ISO UTC `Z`, embedded JSON verbatim, `createdAt`/`updatedAt` as stored. The
 * till's own company, branch and register ids replace ours (IdTranslator::toTill, the company alias this branch
 * uses). Members a newer till sent that we keep in `extra` go back as they came, except secret-looking ones.
 *
 * Never written: secrets (`dropped` members such as `User.remoteApprovalSecret`, hashed `secret` members) and
 * derived members other than `isDeleted` / `domainEvents` (the till ignores derived members when reading).
 */
final class PullPayload
{
    private const SCALES = ['money' => 2, 'cost' => 4, 'quantity' => 4, 'percent' => 4, 'rate' => 6];

    /** @var array<string, string> "kind:ourId" → the till's id */
    private array $ids = [];

    public function __construct(private readonly IdTranslator $translator, private readonly string $branchId) {}

    /**
     * @param  array<string, mixed>  $row  the stored row (any driver)
     * @return array<string, mixed>
     */
    public function envelope(EntityDefinition $def, array $row, int $version): array
    {
        $id = (string) $row['id'];
        $deleted = $row['deleted_at'] !== null;
        $branchId = match (true) {
            // A relayed or drafted row is addressed to the receiving branch; its payload keeps the owner (§10.2, §10.6).
            $def->copy !== null => $this->till(IdKind::Branch, $this->branchId),
            $def->hasScopeColumn('branch_id') && is_string($row['branch_id']) && $row['branch_id'] !== '' => $this->till(IdKind::Branch, $row['branch_id']),
            default => '',
        };

        return [
            'seq' => 0,
            'entity' => $def->entity,
            'entityId' => $id,
            'op' => match (true) {
                $deleted => 'D',
                // §10.2: a relayed row is `I`, or `U` for a receipt that moved on (received → closed).
                $def->copy === 'relay' => $def->entity === 'StockTransferReceipt' && ($row['status'] ?? null) !== 'received' ? 'U' : 'I',
                self::dateTime($row['created_at']) === self::dateTime($row['updated_at']) => 'I',
                default => 'U',
            },
            'version' => $version,
            'companyId' => $this->till(IdKind::Company, (string) $row['company_id']),
            'branchId' => $branchId,
            'registerId' => '',
            'at' => self::dateTime(($row['origin_branch_id'] ?? null) !== null ? $row['synced_at'] : ($row['hub_edited_at'] ?? null))
                ?? self::dateTime($row['updated_at']) ?? self::dateTime(now('UTC')),
            'payload' => $this->payload($def, $row),
            'key' => "{$def->entity}:{$id}:{$version}",
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function payload(EntityDefinition $def, array $row): array
    {
        $payload = [];

        foreach ($def->fields as $name => $field) {
            if ($field->type === 'secret') {
                continue;
            }

            $value = self::value($field->type, $row[$field->column] ?? null);

            if (is_string($value) && $value !== '') {
                $value = match (true) {
                    $name === 'companyId' => $this->till(IdKind::Company, $value),
                    $name === 'branchId' || str_ends_with($name, 'BranchId') => $this->till(IdKind::Branch, $value),
                    $name === 'registerId' || str_ends_with($name, 'RegisterId') => $this->till(IdKind::Register, $value),
                    default => $value,
                };
            }

            $payload[$name] = $value;
        }

        $payload = [
            ...$this->extra($def, $row['extra'] ?? null),
            ...$payload,
            'id' => (string) $row['id'],
            'companyId' => $this->till(IdKind::Company, (string) $row['company_id']),
            'createdAt' => self::dateTime($row['created_at']),
            'updatedAt' => self::dateTime($row['updated_at']),
            'rowVersion' => (int) ($row['row_version'] ?? 1),
            'deletedAt' => self::dateTime($row['deleted_at']),
            'isDeleted' => $row['deleted_at'] !== null,
            'domainEvents' => [],
        ];

        foreach ($def->dropped as $secret) {
            unset($payload[$secret]);
        }

        // §10.6: the till writes a head-office order's shop code itself and never reads `receivedQty` from a pull.
        return match ($def->entity) {
            'PurchaseOrder' => [...$payload, 'branchCode' => ''],
            'PurchaseOrderLine' => [...$payload, 'receivedQty' => 0],
            default => $payload,
        };
    }

    /**
     * Members a newer till sent that the contract does not list yet: back as they came, minus redacted ones.
     *
     * @return array<string, mixed>
     */
    private function extra(EntityDefinition $def, mixed $extra): array
    {
        $members = is_string($extra) ? json_decode($extra, true) : $extra;

        if (! is_array($members)) {
            return [];
        }

        return array_filter($members, fn ($value, $key) => is_string($key)
            && ! Redactor::isSecretKey($key)
            && ! in_array($key, $def->dropped, true)
            && ! str_contains((string) json_encode($value), Redactor::REDACTED), ARRAY_FILTER_USE_BOTH);
    }

    /** Our id → the one the pulling till knows (cached per reply). */
    public function till(IdKind $kind, string $portalId): string
    {
        return $this->ids[$kind->value.':'.$portalId] ??= $this->translator->toTill($kind, $portalId, $this->branchId);
    }

    public static function value(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'money', 'cost', 'quantity', 'percent', 'rate' => self::number(Money::normalise($value, self::SCALES[$type])),
            'int', 'bigint' => (int) $value,
            'bool' => (bool) (int) $value,
            'date' => substr((string) $value, 0, 10),
            'time' => substr((string) $value, 0, 8),
            'datetime' => self::dateTime($value),
            'json' => is_string($value) ? json_decode($value) : $value,
            default => (string) $value,
        };
    }

    /**
     * A fixed-scale decimal as a JSON number with trailing zeros dropped ("1.4500" → 1.45). PHP writes a float with
     * the fewest digits that read back the same, so up to 15 significant digits come out exactly as stored; longer
     * ones go as a numeric string, which the till also reads (contract §6).
     */
    private static function number(string $decimal): int|float|string
    {
        $trimmed = str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;

        if ($trimmed === '-0') {
            $trimmed = '0';
        }

        if (strlen(ltrim(str_replace(['-', '.'], '', $trimmed), '0')) > 15) {
            return $trimmed;
        }

        return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
    }

    public static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return ApiDate::format($value);
        }

        return str_replace(' ', 'T', substr((string) $value, 0, 19)).'Z';
    }
}
