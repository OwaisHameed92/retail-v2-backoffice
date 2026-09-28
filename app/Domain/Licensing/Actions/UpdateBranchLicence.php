<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\BranchLicenceTerm;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Support\TenantLimits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a branch's licence settings, the owner's "customer form" (module 1.11, contract §17.16): tills allowed,
 * trial or full, length and start, features. Every live key of the branch follows at once: features are copied
 * onto the licences and, when the kind, length or start changed, their dates are set again (BranchLicenceTerm).
 * Each till then gets its new token at its next validate, because the claims changed.
 *
 * Tills allowed cannot go below the tills in use (active tills, or live keys of active tills if more).
 */
class UpdateBranchLicence
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch, BranchLicenceSettings $settings): Branch
    {
        $settings->validate();

        return $this->tenancy->runAs($branch->company()->firstOrFail(), fn () => DB::transaction(function () use ($branch, $settings) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->getKey());
            $before = BranchLicenceSettings::of($branch);
            // Every active till has (or gets) a key, so its active tills count as in use too.
            $inUse = max(IssueLicence::keysInUse($branch->id), TenantLimits::activeTills($branch->id));

            if ($settings->maxRegisters < $inUse) {
                throw ValidationException::withMessages(['max_registers' => "{$branch->name} has {$inUse} tills in use. Deactivate a till before allowing fewer."]);
            }

            $branch->forceFill($settings->toAttributes());

            if (! $branch->isDirty()) {
                return $branch;
            }

            $branch->save();

            $changed = $this->applyToLicences($branch, $before, $settings, CarbonImmutable::now());

            $this->audit->handle('branch.licence_updated', $branch, $before->toAudit(), $settings->toAudit(), ['licences' => $changed]);

            return $branch;
        }));
    }

    /** Copies features and (on a new term) dates onto the branch's live keys. Returns how many changed. */
    private function applyToLicences(Branch $branch, BranchLicenceSettings $before, BranchLicenceSettings $settings, CarbonImmutable $now): int
    {
        $newTerm = ! $before->sameTerm($settings);
        $changed = 0;

        $licences = Licence::withoutCompanyScope()->live()->with('plan')->where('branch_id', $branch->id)->lockForUpdate()->get();

        foreach ($licences as $licence) {
            $licence->features = collect(BranchLicenceTerm::features($branch, $licence->plan->features ?? []));

            if ($newTerm) {
                BranchLicenceTerm::applyDates($licence, $branch, $now);
            }

            if ($licence->isDirty()) {
                $licence->save();
                $changed++;
            }
        }

        return $changed;
    }
}
