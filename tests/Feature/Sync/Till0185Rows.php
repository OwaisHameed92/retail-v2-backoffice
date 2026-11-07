<?php

namespace Tests\Feature\Sync;

use App\Domain\TillData\Sync\SyncRowIds;
use Tests\Feature\TillData\TillFixtures;

/**
 * Till 0.1.85 rows (W1) as a Leeds till 1 pushes them: schema-valid against the 0.1.85 pack (every required member,
 * the derived ones included), built on PullTestHelpers::payload.
 */
final class Till0185Rows
{
    /** Derived members the 0.1.85 schemas require (navigation collections and computed flags). */
    private const DERIVED = [
        'JournalEntry' => ['lines' => []],
        'CustomerOrder' => ['balanceDue' => 0, 'isOpen' => true],
    ];

    /** Entities whose rows carry the Leeds till's own branch / register. */
    private const SCOPED = ['DispensingRecord', 'PaymentAttempt', 'JournalEntry', 'JournalLine', 'StockMovement', 'CustomerOrder', 'DispensingItem'];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(string $entity, string $id, array $overrides = []): array
    {
        $scope = in_array($entity, self::SCOPED, true) ? ['branchId' => TillFixtures::LEEDS] : [];

        if (in_array($entity, ['PaymentAttempt', 'JournalEntry', 'JournalLine', 'StockMovement'], true)) {
            $scope['registerId'] = TillFixtures::TILL_1;
        }

        return PullTestHelpers::payload($entity, $id, [...$scope, 'isDeleted' => false, 'domainEvents' => [], ...self::DERIVED[$entity] ?? [], ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $overrides  payload members
     * @return array<string, mixed>
     */
    public static function envelope(string $entity, string $id, int $seq, array $overrides = []): array
    {
        return TillFixtures::envelope($entity, self::payload($entity, $id, $overrides), $seq);
    }

    /**
     * A shared or local Setting row (keyed, §10.3) of the Leeds shop.
     *
     * @return array<string, mixed>
     */
    public static function setting(string $key, string $value, int $seq): array
    {
        $payload = ['scope' => 'branch', 'scopeId' => TillFixtures::LEEDS, 'key' => $key, 'value' => $value, 'updatedAt' => '2026-11-08T09:00:00Z'];

        return [
            'seq' => $seq, 'entity' => 'Setting', 'entityId' => SyncRowIds::setting('branch', TillFixtures::LEEDS, $key),
            'op' => 'I', 'version' => $seq, 'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => '',
            'at' => $payload['updatedAt'], 'payload' => $payload, 'key' => "Setting:{$key}:{$seq}",
        ];
    }
}
