<?php

use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Tenancy\Enums\CompanyRole;
use Tests\Feature\Ai\PortalAssistantHelpers;
use Tests\Feature\Anomalies\AnomalyFixtures as F;

/* Module 6.6: the assistant's list_anomalies tool reports the stored findings as the page shows them to the user. */

uses(PortalAssistantHelpers::class);

beforeEach(function () {
    $this->setUpPortal(); // now: Wed 23 Sept 2026 18:00 London
    $day = ['trading_day' => '2026-09-22'];
    $this->staff = F::anomaly($this->kirkgate->id, $this->leeds->id, AnomalyKind::StaffRefunds, AnomalySeverity::High, $day);
    $this->shop = F::anomaly($this->kirkgate->id, $this->leeds->id, AnomalyKind::SalesGap, AnomalySeverity::Medium, $day);
    F::anomaly($this->kirkgate->id, $this->bradford->id, AnomalyKind::NegativeStock, AnomalySeverity::Low, [...$day, 'status' => AnomalyStatus::Dismissed, 'status_reason' => 'Delivery booked late']);
    F::anomaly($this->other->id, $this->otherShop->id, AnomalyKind::SalesDrop, AnomalySeverity::High, $day);
});

test('owners get every open finding of their business with facts and a link; never another business\'s', function () {
    $this->fake->callTool('list_anomalies', [])->replyWith('Two things to look at.');

    $reply = $this->ask($this->owner, 'Anything unusual this week?');

    $data = $this->toolData();
    expect($this->fake->requests[0]->toolNames())->toContain('list_anomalies')
        ->and($data['total'])->toBe(2)
        ->and(collect($data['findings'])->pluck('id')->sort()->values()->all())->toBe(collect([$this->staff->id, $this->shop->id])->sort()->values()->all())
        ->and(collect($data['findings'])->firstWhere('id', $this->staff->id))->toMatchArray([
            'kind' => 'Refunds by one staff member', 'severity' => 'high', 'staffMember' => 'Staff A', 'link' => '/app/anomalies/'.$this->staff->id,
        ])
        ->and($reply['done']['links'][0]['href'])->toStartWith('/app/anomalies?');

    $this->fake->callTool('list_anomalies', ['status' => 'dismissed'])->replyWith('One dismissed.');
    $this->ask($this->owner, 'And dismissed ones?');
    expect($this->toolData(0, 3)['findings'][0]['statusReason'])->toBe('Delivery booked late');
});

test('accountants never get staff-level findings, and a one-shop manager only their shop', function () {
    $this->fake->callTool('list_anomalies', ['status' => 'all'])->replyWith('One.');
    $this->ask($this->portalMember(CompanyRole::Accountant), 'Anything unusual?');
    $data = $this->toolData();
    expect(collect($data['findings'])->pluck('id')->all())->not->toContain($this->staff->id)
        ->and($data['total'])->toBe(2)
        ->and($data['staffFindingsHidden'])->toBeTrue();

    $this->fake->callTool('list_anomalies', ['status' => 'all', 'shop_id' => $this->leeds->id])->replyWith('Nothing.');
    $this->ask($this->portalMember(CompanyRole::Manager, $this->bradford), 'Anything unusual at Leeds?');
    $data = $this->toolData(0, 3);
    expect($data['shop'])->toBe('Bradford')
        ->and(collect($data['findings'])->pluck('kind')->all())->toBe(['Products going below zero']);
});
