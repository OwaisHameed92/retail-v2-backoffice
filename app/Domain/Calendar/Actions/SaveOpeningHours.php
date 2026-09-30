<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\ShopSettings\Actions\SaveShopSettings;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets a shop's weekly opening hours (module 5.9). The contract has no weekly-hours entity (ownership.json only has
 * the till's own `BranchHoursOverride` special days), so the week is kept on the portal and sent to the shop's tills
 * as the branch `shop.trading_hours` setting text (SaveShopSettings: pulled like any setting, audited). Till health
 * (2.7) uses these hours for "trading time". `null` clears the week: the setting is removed and the default applies.
 *
 * Nothing is written when nothing changed. Audited as `opening_hours.updated` with the old and new week text.
 *
 *     app(SaveOpeningHours::class)->handle($company, $leeds, [1 => ['closed' => false, 'opens' => '07:00', 'closes' => '22:00'], …]);
 */
final class SaveOpeningHours
{
    public function __construct(
        private readonly SaveShopSettings $settings,
        private readonly RecordAudit $audit,
        private readonly CurrentCompany $tenancy,
    ) {}

    /**
     * @param  array<int|string, array{closed?: mixed, opens?: mixed, closes?: mixed}>|null  $week  ISO weekday (1–7) => day
     * @return bool whether anything changed
     *
     * @throws ValidationException
     */
    public function handle(Company $company, Branch $branch, ?array $week): bool
    {
        if ($branch->company_id !== $company->id) {
            throw ValidationException::withMessages(['shop' => 'Choose one of this business\'s shops.']);
        }

        $wanted = $week === null ? null : self::normalise($week);

        return $this->tenancy->runAs($company, fn (): bool => DB::transaction(function () use ($company, $branch, $wanted): bool {
            $rows = ShopOpeningHour::query()->where('branch_id', $branch->id)->get()->keyBy('weekday');
            $before = $rows->isEmpty() ? null : new WeeklyHours($rows->map(fn (ShopOpeningHour $r) => $r->is_closed ? null : ['opens' => (string) $r->opens_at, 'closes' => (string) $r->closes_at])->all());

            if ($before?->text() === $wanted?->text()) {
                return false;
            }

            if ($wanted === null) {
                ShopOpeningHour::query()->where('branch_id', $branch->id)->delete();
            } else {
                foreach ($wanted->days as $weekday => $day) {
                    ShopOpeningHour::query()->updateOrCreate(
                        ['branch_id' => $branch->id, 'weekday' => $weekday],
                        ['company_id' => $company->id, 'is_closed' => $day === null, 'opens_at' => $day['opens'] ?? null, 'closes_at' => $day['closes'] ?? null],
                    );
                }
            }

            $this->settings->handle($company, $branch, ['shop.trading_hours' => $wanted?->text()]);
            $this->audit->handle('opening_hours.updated', $branch, ['week' => $before?->text()], ['week' => $wanted?->text()], [
                'branch_id' => $branch->id,
            ], companyId: $company->id);

            return true;
        }));
    }

    /**
     * @param  array<int|string, array{closed?: mixed, opens?: mixed, closes?: mixed}>  $week
     *
     * @throws ValidationException
     */
    public static function normalise(array $week): WeeklyHours
    {
        $days = [];
        $errors = [];

        foreach (WeeklyHours::DAY_NAMES as $weekday => $name) {
            $day = $week[$weekday] ?? $week[(string) $weekday] ?? null;

            if (! is_array($day)) {
                $errors["days.{$weekday}"] = "Give {$name}'s hours or mark it closed.";

                continue;
            }

            if (filter_var($day['closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $days[$weekday] = null;

                continue;
            }

            $opens = is_string($day['opens'] ?? null) ? trim($day['opens']) : '';
            $closes = is_string($day['closes'] ?? null) ? trim($day['closes']) : '';

            if (preg_match(WeeklyHours::TIME, $opens) !== 1) {
                $errors["days.{$weekday}.opens"] = "Give {$name}'s opening time as HH:MM.";
            }

            if (preg_match(WeeklyHours::TIME, $closes) !== 1) {
                $errors["days.{$weekday}.closes"] = "Give {$name}'s closing time as HH:MM.";
            }

            $days[$weekday] = ['opens' => $opens, 'closes' => $closes];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new WeeklyHours($days);
    }
}
