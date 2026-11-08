<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Workshop\WorkshopFixtures as W;
use Tests\Support\ContractSchema;
use Tests\Support\ProposalSchema;

/*
 * Contract proposal workshop-order (docs/contracts/proposals/workshop-order): the sample and every live pull row match
 * the till's own 0.1.85 WorkshopOrder entity schema, the proposal's connector schema and the linesJson schema.
 */

const WORKSHOP_PROPOSAL = 'proposals/workshop-order';

function workshopPayloadErrors(array $payload): array
{
    return [
        ...ContractSchema::errors($payload, 'schemas/entities/WorkshopOrder.schema.json'),
        ...ProposalSchema::errorsIn(WORKSHOP_PROPOSAL, $payload, 'schemas/WorkshopOrder.connector.schema.json'),
        ...ProposalSchema::errorsIn(WORKSHOP_PROPOSAL, json_decode($payload['linesJson'], true), 'schemas/WorkshopOrderLines.schema.json'),
    ];
}

test('the proposal\'s sample pull page is a valid pull reply whose rows match the entity, connector and lines schemas', function () {
    $sample = json_decode((string) file_get_contents(base_path('docs/contracts/'.WORKSHOP_PROPOSAL.'/samples/pull-reply.workshop.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(ContractSchema::pullErrors($sample))->toBe([]);

    foreach ($sample['changes'] as $change) {
        expect(workshopPayloadErrors($change['payload']))->toBe([])
            ->and($change['key'])->toBe("WorkshopOrder:{$change['entityId']}:{$change['payload']['rowVersion']}");
    }
});

test('a live pull row matches the same schemas, before and after a change and a cancel', function () {
    $this->travelTo('2026-11-08 09:00:00');
    Http::fake();
    $sync = new SyncApiFixtures($this);
    $key = W::connected($sync);
    $since = (int) $sync->pull(0)->json('highestVersion');

    $id = W::call($this, 'POST', '/SalesOrder', $key, W::order($sync->leeds, ['discount' => ['type' => 'percentage', 'value' => 10], 'isWarranty' => true, 'dueAt' => '2026-11-10']))->json('id');
    W::call($this, 'POST', '/SalesOrderLine', $key, ['salesOrderId' => $id, 'name' => 'Call-out fee', 'quantity' => 1, 'paidPrice' => 5, 'lineType' => 'Fee'])->assertOk();
    $live = W::workshopRows($sync->pull($since));
    W::call($this, 'DELETE', '/SalesOrder?id='.$id, $key)->assertOk();
    $cancelled = W::workshopRows($sync->pull($since));

    expect($live)->toHaveCount(1)->and($cancelled)->toHaveCount(1)
        ->and(workshopPayloadErrors($live[0]['payload']))->toBe([])
        ->and(workshopPayloadErrors($cancelled[0]['payload']))->toBe([])
        ->and($live[0]['payload'])->toMatchArray(['discountType' => 'percentage', 'discountValue' => 10, 'goodsTotal' => 63.45, 'billingType' => 'warranty', 'isWarranty' => true, 'dueAt' => '2026-11-10T00:00:00Z'])
        ->and(collect(json_decode($live[0]['payload']['linesJson'], true))->last()['lineType'])->toBe('fee')
        ->and($cancelled[0]['payload']['status'])->toBe('cancelled');
});
