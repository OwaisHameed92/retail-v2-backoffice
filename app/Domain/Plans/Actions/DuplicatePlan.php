<?php

namespace App\Domain\Plans\Actions;

use App\Domain\Plans\Data\PlanData;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DuplicatePlan
{
    private const NAME_MAX = 100;

    private const CODE_MAX = 50;

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Copy a plan (archived plans too) as "<name> (copy)" with a free "<code>-copy[-n]" code. The copy starts
     * inactive and hidden so it is never offered before someone reviews it. Records `plan.duplicated` on the copy.
     */
    public function handle(Plan $source): Plan
    {
        return DB::transaction(function () use ($source) {
            $copy = $source->replicate(['deleted_at', 'created_at', 'updated_at']);
            $copy->name = Str::limit($source->name, self::NAME_MAX - 7, '').' (copy)';
            $copy->code = $this->freeCode($source->code);
            $copy->is_active = false;
            $copy->is_public = false;
            $copy->save();

            $this->audit->handle('plan.duplicated', $copy, null, PlanData::audit($copy), [
                'source_plan_id' => $source->id,
                'source_plan_name' => $source->name,
            ]);

            return $copy;
        });
    }

    private function freeCode(string $code): string
    {
        $base = rtrim(Str::limit($code, self::CODE_MAX - 8, ''), '-').'-copy';
        $candidate = $base;
        $n = 2;

        while (Plan::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = $base.'-'.$n++;
        }

        return $candidate;
    }
}
