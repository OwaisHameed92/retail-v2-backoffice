<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\Redactor;
use App\Domain\Staff\Support\TillPinHasher;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\TillData\Registry\EntityDefinition;

/**
 * One stored hub-owned row → one pull envelope in the till's shape (module 2.5, contract §5, §6, §11): camelCase
 * members, every stored member written (nulls too), money as numbers with at most 2 dp (costs, quantities 4),
 * enums as their strings, date-times ISO UTC `Z`, embedded JSON verbatim, `createdAt`/`updatedAt` as stored. The
 * till's own company, branch and register ids replace ours (IdTranslator::toTill, the company alias this branch
 * uses). Members a newer till sent that we keep in `extra` go back as they came, except secret-looking ones.
 *
 * Never written: secrets (`dropped` members such as `User.remoteApprovalSecret`, hashed `secret` members),
 * derived members other than `isDeleted` / `domainEvents` (the till ignores derived members when reading), a
 * blank `User.pinHash` / `User.rfid` (KEPT_WHEN_BLANK), and a `User.pinHash` this till already has or cannot read
 * (sendsPin: only to set or change a PIN, in the till's `pbkdf2$…` format, ANSWERS-2026-10-01 §1). Also never
 * written: `Customer.pendingPoints` (NEVER_SENT), and a recall's close / return members in a `U` (TILL_KEEPS_ON_UPDATE).
 */
final class PullPayload
{
    private const SCALES = ['money' => 2, 'cost' => 4, 'quantity' => 4, 'percent' => 4, 'rate' => 6];

    /**
     * Members never sent while blank (null or ""): the till keeps its own value when a member is missing
     * (ANSWERS-2026-09-29-b A.1, §10.7). A blank `rfid` wiped the fob on tills up to 0.1.8; a blank `pinHash` never
     * clears a PIN. The till's own values arrive by push; the portal only sends one it holds.
     */
    public const KEPT_WHEN_BLANK = ['User' => ['pinHash', 'rfid']];

    /**
     * Members never sent (ANSWERS-2026-10-06 Q1): `Customer.pendingPoints` is each till's own figure (points held on
     * that till's unpaid sales), so an echo would overwrite another shop's; a missing member keeps the till's value,
     * a `null` would reject the whole row. What a till pushes is stored, but it is not the truth.
     */
    public const NEVER_SENT = ['Customer' => ['pendingPoints']];

    /**
     * Members left out of a `U` (ANSWERS-2026-10-06 Q3): the tills close, reopen, return and note a recall; the portal
     * raises it (an `I` sends the whole row) and edits its text only. Left out, each till keeps its own values.
     */
    public const TILL_KEEPS_ON_UPDATE = ['ProductRecall' => ['status', 'closedAt', 'closedByUserId', 'note', 'returnedQty']];

    /** @return list<string> members a payload of this entity may lack (contract tests relax `required` by these) */
    public static function omittable(string $entity): array
    {
        return [...self::KEPT_WHEN_BLANK[$entity] ?? [], ...self::NEVER_SENT[$entity] ?? [], ...self::TILL_KEEPS_ON_UPDATE[$entity] ?? []];
    }

    /** @var array<string, string> "kind:ourId" → the till's id */
    private array $ids = [];

    /** @param  int  $since  the pulling till's cursor: a PIN versioned at or below it is already on the till */
    public function __construct(private readonly IdTranslator $translator, private readonly string $branchId, private readonly int $since = 0) {}

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

        $op = match (true) {
            $deleted => 'D',
            // §10.2: a relayed row is `I`, or `U` for a receipt that moved on (received → closed).
            $def->copy === 'relay' => $def->entity === 'StockTransferReceipt' && ($row['status'] ?? null) !== 'received' ? 'U' : 'I',
            self::dateTime($row['created_at']) === self::dateTime($row['updated_at']) => 'I',
            default => 'U',
        };

        return [
            'seq' => 0,
            'entity' => $def->entity,
            'entityId' => $id,
            'op' => $op,
            'version' => $version,
            'companyId' => $this->till(IdKind::Company, (string) $row['company_id']),
            'branchId' => $branchId,
            'registerId' => '',
            'at' => self::dateTime(($row['origin_branch_id'] ?? null) !== null ? $row['synced_at'] : ($row['hub_edited_at'] ?? null))
                ?? self::dateTime($row['updated_at']) ?? self::dateTime(now('UTC')),
            'payload' => $this->payload($def, $row, $op),
            'key' => "{$def->entity}:{$id}:{$version}",
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function payload(EntityDefinition $def, array $row, string $op): array
    {
        $payload = [];

        foreach ($def->fields as $name => $field) {
            if ($field->type === 'secret') {
                continue;
            }

            $value = self::value($field->type, $row[$field->column] ?? null);

            // A row for every shop keeps its branch blank; the till's member is a string ("" = every shop).
            if ($value === null && $name === 'branchId' && ! $field->nullable) {
                $value = '';
            }

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

        if ($def->entity === 'User' && ! $this->sendsPin($row)) {
            unset($payload['pinHash']);
        }

        foreach (self::KEPT_WHEN_BLANK[$def->entity] ?? [] as $member) {
            if (($payload[$member] ?? null) === null || $payload[$member] === '') {
                unset($payload[$member]);
            }
        }

        $omit = [...self::NEVER_SENT[$def->entity] ?? [], ...($op === 'U' ? self::TILL_KEEPS_ON_UPDATE[$def->entity] ?? [] : [])];
        $payload = array_diff_key($payload, array_flip($omit));

        // §10.6: the till writes a head-office order's shop code itself and never reads `receivedQty` from a pull.
        return match ($def->entity) {
            'PurchaseOrder' => [...$payload, 'branchCode' => ''],
            'PurchaseOrderLine' => [...$payload, 'receivedQty' => 0],
            'PromotionRule' => [...$payload, 'isGroupOffer' => self::isGroupOffer($payload)],
            'Customer' => self::customer($payload),
            default => $payload,
        };
    }

    /**
     * ANSWERS-2026-09-29-b "Naya field": the till works `isGroupOffer` out itself (a discount once per whole group of
     * `minQuantity` pieces): minQuantity ≥ 2, a % off / £ off / fixed price rule, not on the whole basket. Sent as
     * the rule now reads, never a stale stored value.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function isGroupOffer(array $payload): bool
    {
        return (int) ($payload['minQuantity'] ?? 0) >= 2
            && in_array($payload['type'] ?? null, ['percentOff', 'fixedOff', 'fixedPrice'], true)
            && ($payload['scope'] ?? null) !== 'basket';
    }

    /**
     * Till 0.1.28–0.1.51 (PORTAL-CHANGES-2026-10-06 §2.1, §2.7): `earnsPoints` is always sent and never defaulted to
     * false (a row from before 0.1.32, or one the portal made, collects points: the till reads a missing value as
     * true). `pendingPoints` is never sent (NEVER_SENT). `owed` / `creditHeld` are worked out from the balance we send
     * (the ledger's sum), never stored; the till never reads them (ANSWERS-2026-10-06 Q2).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function customer(array $payload): array
    {
        $balance = Money::normalise($payload['balance'] ?? 0);
        $negative = Money::isNegative($balance);

        return [
            ...$payload,
            'earnsPoints' => $payload['earnsPoints'] ?? true,
            'owed' => $negative ? 0 : self::number($balance),
            'creditHeld' => $negative ? self::number(ltrim($balance, '-')) : 0,
        ];
    }

    /**
     * §10.7: `pinHash` only to set or change a PIN — the hash changed after this till's cursor (HubVersions versions
     * it) — and only in the till's format; an Identity v3 hash from an older portal is "PIN needs resetting".
     *
     * @param  array<string, mixed>  $row
     */
    private function sendsPin(array $row): bool
    {
        $hash = $row['pin_hash'] ?? null;

        return is_string($hash) && TillPinHasher::isTillFormat($hash)
            && ($row['pin_hash_versioned'] ?? null) === $hash
            && (int) ($row['pin_hash_version'] ?? 0) > $this->since;
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
