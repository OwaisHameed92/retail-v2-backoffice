<?php

use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Contracts\AiWriteTool;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\PurchaseOrder;
use Tests\Feature\Ai\AiSecurityFixtures as S;
use Tests\Feature\Ai\PortalAssistantHelpers;

/*
 * AI security review: EVERY registered tenant tool (including tools added later: inputs are built from each tool's
 * schema) is run by the model for Kirkgate, and nothing of another business, of another shop for a one-shop manager,
 * or personal data (PIN, fob, pay rate, contact details) may reach the model. Write tools only ever propose.
 */

uses(PortalAssistantHelpers::class);

beforeEach(function () {
    $this->setUpPortal();
    S::seed($this->kirkgate, $this->leeds, $this->bradford, $this->other, $this->otherShop);
});

/** Ask once, with the model calling every tenant tool with these ids; returns the JSON of everything sent to the model. */
function securityRunEveryTool(object $test, mixed $user, array $ids): array
{
    $tools = S::tenantTools();
    $test->fake->callTools(array_map(fn (AiTool $t) => ['name' => $t->name(), 'input' => S::input($t, $ids)], $tools))->replyWith('Done.');
    $test->ask($user, 'Tell me everything');

    $results = collect($test->fake->toolResultsIn())->values();

    return [
        'tools' => $tools,
        'results' => $results->mapWithKeys(fn (array $r, int $i) => [$tools[$i]->name() => $r])->all(),
        'sent' => json_encode($test->fake->requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'resultsJson' => json_encode($results->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

test('every tenant tool runs for Kirkgate\'s owner and never returns another business\'s data or personal data', function () {
    $run = securityRunEveryTool($this, $this->owner, S::ids('a', $this->otherShop));

    expect(count($run['tools']))->toBeGreaterThanOrEqual(15);

    // The tools written before this review must all succeed on Kirkgate's own ids (later tools: isolation only).
    $core = ['get_company_overview', 'rename_branch', 'get_sales', 'get_product_sales', 'get_refunds_and_voids', 'get_stock', 'find_products',
        'get_customers_owing', 'get_cash_variances', 'get_staff_hours', 'get_vat_summary', 'get_till_health', 'draft_purchase_order',
        'suggest_reorder', 'queue_labels'];
    foreach ($core as $name) {
        expect($run['results'][$name]['is_error'] ?? null)->toBeFalse("{$name}: ".($run['results'][$name]['content'] ?? 'not run'));
    }

    foreach ([...S::B_MARKERS, $this->other->id, $this->otherShop->id, S::B_PRODUCT, S::B_SUPPLIER, S::B_CUSTOMER, S::B_STAFF] as $marker) {
        expect($run['sent'])->not->toContain($marker);
    }

    foreach (S::PII as $personal) {
        expect($run['resultsJson'])->not->toContain($personal);
    }

    // Kirkgate's own data did come back (the check above is not passing on empty results).
    expect($run['resultsJson'])->toContain('Jane Owes')->toContain('Sam Leeds')->toContain('Coca-Cola');
});

test('write tools only propose: nothing changes until a person confirms, and the proposal is bound to them', function () {
    securityRunEveryTool($this, $this->owner, S::ids('a', $this->otherShop));

    $writeTools = array_filter(S::tenantTools(), fn (AiTool $t) => $t instanceof AiWriteTool);
    $proposals = AiPendingAction::query()->where('company_id', $this->kirkgate->id)->get();

    expect($proposals->pluck('tool')->sort()->values()->all())->toBe(collect($writeTools)->map->name()->sort()->values()->all())
        ->and($proposals->every(fn (AiPendingAction $a) => $a->status === PendingActionStatus::Pending && $a->user_id === $this->owner->id
            && $a->signature !== null && $a->executed_at === null))->toBeTrue()
        ->and(Branch::query()->whereKey($this->leeds->id)->withoutGlobalScopes()->value('name'))->toBe('Leeds')
        ->and(PurchaseOrder::withoutCompanyScope()->where('company_id', $this->kirkgate->id)->where('origin', 'headOffice')->count())->toBe(0)
        ->and(LabelQueueItem::withoutCompanyScope()->where('company_id', $this->kirkgate->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'ai.action_confirmed')->count())->toBe(0);

    // Another business's owner and a colleague cannot confirm them; the proposer can, exactly once.
    $otherOwner = $this->portalMember(CompanyRole::Owner, company: $this->other);
    $colleague = $this->portalMember(CompanyRole::Owner);
    $rename = $proposals->firstWhere('tool', 'rename_branch');

    $this->actingAs($otherOwner)->postJson("/app/assistant/actions/{$rename->id}/confirm")->assertNotFound();
    $this->actingAs($colleague)->postJson("/app/assistant/actions/{$rename->id}/confirm")->assertNotFound();
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$rename->id}/confirm")->assertOk();
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$rename->id}/confirm")->assertStatus(409);

    expect(Branch::query()->whereKey($this->leeds->id)->withoutGlobalScopes()->value('name'))->toBe('Security review');
});

test('IDOR: every tenant tool called with another business\'s ids finds nothing and proposes nothing', function () {
    $run = securityRunEveryTool($this, $this->owner, S::ids('b', $this->otherShop));

    foreach (S::B_MARKERS as $marker) {
        expect($run['resultsJson'])->not->toContain($marker);
    }

    $bIds = [$this->otherShop->id, S::B_PRODUCT, S::B_SUPPLIER, S::B_CUSTOMER];
    foreach (AiPendingAction::query()->get() as $proposal) {
        foreach ($bIds as $id) {
            expect(json_encode($proposal->input))->not->toContain($id);
        }
    }

    // Tools that take a shop, product or supplier id refuse the other business's.
    foreach (['get_sales', 'get_stock', 'rename_branch', 'draft_purchase_order', 'suggest_reorder', 'queue_labels'] as $name) {
        expect($run['results'][$name]['is_error'])->toBeTrue($name);
    }
});

test('one-shop pinning: a Leeds-only manager\'s tools never return Bradford, whatever ids the model sends', function () {
    $manager = $this->portalMember(CompanyRole::Manager, $this->leeds);

    $run = securityRunEveryTool($this, $manager, S::ids('bradford', $this->otherShop));

    foreach ([...S::BRADFORD_MARKERS, ...S::B_MARKERS] as $marker) {
        expect($run['resultsJson'])->not->toContain($marker);
    }

    // Overview: only their shop. Rename: refused for another shop. Head-office orders: refused for one-shop users.
    expect($run['results']['get_company_overview']['content'])->toContain('Leeds')->toContain('"limitedToOneShop":true')
        ->and($run['results']['rename_branch']['is_error'])->toBeTrue()
        ->and($run['results']['rename_branch']['content'])->toContain('only change their own shop')
        ->and($run['results']['draft_purchase_order']['is_error'])->toBeTrue()
        ->and(AiPendingAction::query()->where('tool', 'rename_branch')->exists())->toBeFalse()
        ->and(AiPendingAction::query()->where('tool', 'queue_labels')->value('input')['shop_id'] ?? null)->toBe($this->leeds->id);
});
