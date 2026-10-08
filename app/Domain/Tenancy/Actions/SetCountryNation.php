<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\TillProfile;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pak POS pack 2026-10-07: every Pakistan shop carries Branch `nation` "Pakistan". Shops made before (on the portal or
 * by a till) still hold the column default `england`; this sets them to the profile's nation through the model, so
 * each change is audited (`branch.updated`) and queued for the shop's till (SentToTills). Other values are left alone.
 * Only where the profile names a nation (PK); idempotent. Run by `branches:pakistan-nation`.
 */
final class SetCountryNation
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return list<array{company: string, branch: string, code: string}> the shops changed (or that would be)
     *
     * @throws LogicException where the profile has no fixed nation (GB)
     */
    public function handle(bool $dryRun = true): array
    {
        $nation = TillProfile::branchNation() ?? throw new LogicException('This instance\'s country gives shops no fixed nation (the UK picks one per shop).');
        $target = Nation::from($nation);
        $branches = Branch::withoutCompanyScope()->with('company')->where('nation', Nation::England->value)
            ->orderBy('company_id')->orderBy('code')->get();
        $rows = [];

        foreach ($branches as $branch) {
            $rows[] = ['company' => (string) $branch->company?->name, 'branch' => $branch->name, 'code' => $branch->code];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($branch, $target) {
                $branch->nation = $target;
                $branch->save();
                $this->audit->handle('branch.updated', $branch, ['nation' => Nation::England->value], ['nation' => $target->value], ['reason' => 'branches:pakistan-nation']);
            });
        }

        return $rows;
    }
}
