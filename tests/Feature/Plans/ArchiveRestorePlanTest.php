<?php

use App\Domain\Plans\Actions\ArchivePlan;
use App\Domain\Plans\Actions\RestorePlan;
use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Validation\ValidationException;

use function Tests\Feature\Plans\planAdmin;

require_once __DIR__.'/PlanTestHelpers.php';

it('archives a plan as a soft delete and records it', function () {
    $admin = planAdmin();
    $this->actingAs($admin, 'admin');
    $plan = Plan::factory()->create(['name' => 'Standard']);

    app(ArchivePlan::class)->handle($plan);

    expect(Plan::query()->find($plan->id))->toBeNull()
        ->and(Plan::withTrashed()->find($plan->id)->status())->toBe(PlanStatus::Archived);

    $log = AuditLog::query()->sole();
    expect($log->action)->toBe('plan.archived')
        ->and($log->subject_id)->toBe($plan->id)
        ->and($log->actor_id)->toBe($admin->id);
});

it('does nothing when the plan is already archived', function () {
    $plan = Plan::factory()->archived()->create();

    app(ArchivePlan::class)->handle($plan);

    expect(AuditLog::query()->count())->toBe(0);
});

it('reports that plans are not in use until licences exist', function () {
    expect(Plan::factory()->create()->isInUse())->toBeFalse();
});

it('blocks archiving a plan that licences use', function () {
    $stored = Plan::factory()->create(['name' => 'Standard']);
    $inUse = new class extends Plan
    {
        public function isInUse(): bool
        {
            return true;
        }
    };
    $plan = $inUse->newFromBuilder($stored->getAttributes());

    expect(fn () => app(ArchivePlan::class)->handle($plan))
        ->toThrow(ValidationException::class, 'Standard is used by licences, so it cannot be archived.');

    expect(Plan::query()->find($stored->id))->not->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('restores an archived plan with its settings and records it', function () {
    $plan = Plan::factory()->archived()->create(['price_monthly' => '45.00', 'is_public' => false]);

    app(RestorePlan::class)->handle($plan);

    $plan = Plan::query()->findOrFail($plan->id);
    expect($plan->price_monthly)->toBe('45.00')
        ->and($plan->status())->toBe(PlanStatus::Hidden)
        ->and(AuditLog::query()->sole()->action)->toBe('plan.restored');
});

it('does nothing when restoring a plan that is not archived', function () {
    app(RestorePlan::class)->handle(Plan::factory()->create());

    expect(AuditLog::query()->count())->toBe(0);
});
