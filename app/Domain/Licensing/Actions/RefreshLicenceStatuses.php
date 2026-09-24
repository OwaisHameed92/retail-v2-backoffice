<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves stored statuses along their dates (trial → grace → expired, active → grace → expired). Suspended,
 * revoked and never-activated licences are left alone. Idempotent: a second run the same day changes nothing.
 * Run daily by `licences:refresh`. Each change is audited as the system.
 */
class RefreshLicenceStatuses
{
    private const MOVABLE = [LicenceStatus::Trial, LicenceStatus::Active, LicenceStatus::Grace, LicenceStatus::Expired];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return array<string, int> "from→to" => count, e.g. ["trial→grace" => 2]
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $moves = [];

        Licence::withoutCompanyScope()
            ->whereIn('status', array_map(fn (LicenceStatus $s) => $s->value, self::MOVABLE))
            ->whereNotNull('activated_at')
            ->chunkById(500, function ($licences) use ($now, &$moves): void {
                foreach ($licences as $licence) {
                    /** @var Licence $licence */
                    $to = LicenceTerms::naturalStatus($licence, $now);

                    if ($to === $licence->status) {
                        continue;
                    }

                    $from = $licence->status;

                    DB::transaction(function () use ($licence, $from, $to): void {
                        $licence->status = $to;
                        $licence->save();

                        $this->audit->handle('licence.status_changed', $licence, ['status' => $from->value], ['status' => $to->value], ['source' => 'licences:refresh']);
                    });

                    $key = $from->value.'→'.$to->value;
                    $moves[$key] = ($moves[$key] ?? 0) + 1;
                }
            });

        return $moves;
    }
}
