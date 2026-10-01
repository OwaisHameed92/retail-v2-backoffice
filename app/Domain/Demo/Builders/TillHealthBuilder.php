<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillHealth\Actions\RefreshTillHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What Till health reads for the demo tills: each till's licence in use on a PC (issued if it had none, a new one
 * started as a trial) with a recent check-in and diagnostics, each shop's sync contact, and the hardware checks the
 * tills ran (all passing but a label printer). One back till last checked in a few hours ago, so the page shows a
 * warning. Then the health tables are refreshed for the business.
 */
final class TillHealthBuilder
{
    public const APP_VERSION = '0.1.19';

    public function __construct(
        private readonly IssueLicence $issue,
        private readonly RefreshTillHealth $refresh,
    ) {}

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $n = 0;

        foreach ($b->shops as ['branch' => $branch, 'shop' => $shop]) {
            foreach ($shop->registers as $i => ['id' => $registerId, 'code' => $code]) {
                $stale = $n === 1 && count($shop->registers) > 1;
                $this->licence($b, $registerId, "{$shop->branchCode}-TILL-{$code}", $n++, $stale);

                foreach (['receiptPrinter', 'cashDrawer', 'scanner', 'paymentTerminal', 'customerDisplay', 'labelPrinter'] as $k => $kind) {
                    if ($kind === 'labelPrinter' && $i > 0) {
                        continue;
                    }

                    $at = $b->now->subDays($k % 3)->subMinutes(30 + $k * 7);
                    $fail = $kind === 'labelPrinter';
                    $push->add($shop, 'HardwareCheck', $shop->id("hardware|{$registerId}|{$kind}"), [
                        'kind' => $kind, 'result' => $fail ? 'fail' : 'pass', 'adapter' => match ($kind) {
                            'paymentTerminal' => 'Dojo Go', 'receiptPrinter' => 'Epson TM-T20III', 'labelPrinter' => 'Zebra GK420d', default => 'USB HID'
                        },
                        'port' => $kind === 'receiptPrinter' ? 'USB001' : ($kind === 'labelPrinter' ? 'USB003' : ''),
                        'evidence' => $fail ? 'Printer did not answer: offline or out of labels' : 'Test passed', 'instruction' => $fail ? 'Check the label roll and the USB cable, then run the test again' : '',
                        'elapsedMs' => $fail ? 5000 : 180 + $k * 40, 'at' => DemoBusiness::iso($at), 'userId' => DemoStaff::manager($shop), 'userName' => 'Shop manager',
                        'confirmsCheckId' => '', 'notes' => '', 'registerId' => $registerId, 'branchId' => $shop->branchId,
                    ], $at);
                }
            }

            $now = $b->now->format('Y-m-d H:i:s');
            $status = [
                'last_hello_at' => $b->now->subMinutes(4)->format('Y-m-d H:i:s'), 'last_push_at' => $b->now->subMinutes(2)->format('Y-m-d H:i:s'),
                'last_app_version' => self::APP_VERSION, 'last_register_id' => $shop->registers[0]['id'], 'updated_at' => $now,
            ];

            if (DB::table('sync_branch_status')->where('branch_id', $branch->id)->exists()) {
                DB::table('sync_branch_status')->where('branch_id', $branch->id)->where('company_id', $b->companyId)->update($status);
            } else {
                DB::table('sync_branch_status')->insert($status + ['id' => Ulid::new(), 'company_id' => $b->companyId, 'branch_id' => $branch->id, 'created_at' => $now]);
            }
        }

        $push->flush();
        $this->refresh->handle($b->now, [$b->companyId]);
    }

    private function licence(DemoBusiness $b, string $registerId, string $device, int $n, bool $stale): void
    {
        $licence = Licence::withoutCompanyScope()->where('live_register_id', $registerId)->first();

        if ($licence === null) {
            $register = Register::withoutCompanyScope()->whereKey($registerId)->firstOrFail();

            try {
                $licence = Licence::withoutCompanyScope()->whereKey($this->issue->handle($register)->licence->id)->firstOrFail();
            } catch (ValidationException) {
                return; // no plan to licence tills with yet: the till shows as unlicensed
            }
        }

        if ($licence->status === LicenceStatus::Issued) {
            $licence->forceFill(['status' => LicenceStatus::Trial, 'activated_at' => $b->now->subDays(3), 'trial_ends_at' => $b->now->addDays(4)]);
        }

        $licence->forceFill([
            'device_id' => $licence->device_id ?? 'DEMO-'.strtoupper(substr(md5($device), 0, 12)),
            'device_name' => $licence->device_name ?? $device,
            'bound_at' => $licence->bound_at ?? $b->now->subDays(3),
            'last_check_in_at' => $stale ? $b->now->subHours(5) : $b->now->subMinutes(3 + $n * 4),
            'last_validated_at' => $stale ? $b->now->subHours(5) : $b->now->subMinutes(3 + $n * 4),
            'last_app_version' => self::APP_VERSION,
            'diagnostics' => ['pendingSyncRows' => $stale ? 214 : 0, 'databaseSizeMb' => 180.5 + $n * 12],
            'diagnostics_at' => $stale ? $b->now->subHours(5) : $b->now->subMinutes(3 + $n * 4),
        ])->save();
    }
}
