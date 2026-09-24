<?php

use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Tenancy\Enums\CompanyRole;
use Tests\Feature\Ai\AiTestHelpers;

uses(AiTestHelpers::class);

beforeEach(fn () => $this->installFakeAi());

test('ai:ask answers a question about a company as a read-only job', function () {
    $company = $this->aiCompany(name: 'Khan Mini Mart');
    $this->fake->callTool('get_company_overview')->replyWith('You have one branch.');

    $this->artisan('ai:ask', ['company' => 'Khan Mini Mart', 'question' => 'How many branches?'])
        ->expectsOutputToContain('You have one branch.')
        ->expectsOutputToContain('2 step(s)')
        ->assertSuccessful();

    expect($this->fake->requests[0]->toolNames())->toBe(['get_company_overview']);
});

test('ai:ask as a member can propose a change but never confirms it', function () {
    $company = $this->aiCompany();
    $user = $this->member($company, CompanyRole::Owner);
    $branch = $this->branchOf($company);
    $this->fake->callTool('rename_branch', ['branch_id' => $branch->id, 'new_name' => 'Corner Shop'])->replyWith('Please confirm.');

    $this->artisan('ai:ask', ['company' => $company->id, 'question' => 'Rename it', '--user' => $user->email])
        ->expectsOutputToContain('Proposed (not confirmed): Rename branch "Main shop" (MAIN) to "Corner Shop".')
        ->assertSuccessful();

    expect(AiPendingAction::query()->sole()->status->value)->toBe('pending')
        ->and($branch->fresh()->name)->toBe('Main shop');
});

test('ai:ask explains when AI is not available', function () {
    $company = $this->aiCompany();
    $this->fake->notConfigured();

    $this->artisan('ai:ask', ['company' => $company->id, 'question' => 'Hi'])
        ->expectsOutputToContain('not set up yet')
        ->assertFailed();
});

test('ai:ask rejects unknown companies and non-members', function () {
    $company = $this->aiCompany();

    $this->artisan('ai:ask', ['company' => 'Nobody Ltd', 'question' => 'Hi'])->assertFailed();
    $this->artisan('ai:ask', ['company' => $company->id, 'question' => 'Hi', '--user' => 'stranger@example.test'])
        ->expectsOutputToContain('not an active member')
        ->assertFailed();
});
