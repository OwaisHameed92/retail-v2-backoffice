<?php

use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/** Till 0.1.29–0.1.37 push EventSubscription bookkeeping with an empty companyId: skipped so the queue never stalls. */
it('acknowledges EventSubscription rows with an empty companyId without storing them', function () {
    [$company, $leeds] = TillFixtures::tenant();
    $row = fn (int $seq, string $companyId) => [
        'seq' => $seq, 'entity' => 'EventSubscription', 'entityId' => '01M41S74PBG69ACDTWJ7F1PY7D', 'op' => 'U', 'version' => $seq,
        'companyId' => $companyId, 'branchId' => '', 'registerId' => '', 'at' => '2026-10-03T21:40:00Z',
        'payload' => ['id' => '01M41S74PBG69ACDTWJ7F1PY7D', 'companyId' => $companyId, 'handlerName' => 'StockProjection', 'lastSeq' => 120,
            'createdAt' => '2026-10-03T21:00:00Z', 'updatedAt' => '2026-10-03T21:40:00Z', 'rowVersion' => $seq, 'deletedAt' => null, 'isDeleted' => false],
    ];

    $result = TillFixtures::apply($company, $leeds, [$row(1, ''), $row(2, '')]);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 2, 'accepted' => 2])
        ->and($result->count(ChangeOutcome::Skipped))->toBe(2)
        ->and(DB::table('sync_applied_changes')->count())->toBe(0);
});

it('still refuses an EventSubscription row that names another company', function () {
    [$company, $leeds] = TillFixtures::tenant();

    $result = TillFixtures::apply($company, $leeds, [[
        'seq' => 1, 'entity' => 'EventSubscription', 'entityId' => '01M41S74PBG69ACDTWJ7F1PY7D', 'op' => 'U', 'version' => 1,
        'companyId' => '01M3YKNDEJKKVD6KBE44KGVVPF', 'branchId' => '', 'registerId' => '', 'at' => '2026-10-03T21:40:00Z',
        'payload' => ['id' => '01M41S74PBG69ACDTWJ7F1PY7D', 'handlerName' => 'x', 'lastSeq' => 1],
    ]]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['sync.wrong_company']);
});
