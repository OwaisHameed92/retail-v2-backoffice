<?php

namespace App\Domain\Shared\Country;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * UK till modules a country profile can hide (Pakistan plan P10, owner 2026-10-07): flags in the profile's `features`
 * (config/country.php), all on for GB, off for PK. A module that is off is hidden in the portal only: its pages,
 * form fields, till settings and portal actions (routes answer 404). Till data is never touched: tills push and pull
 * these fields exactly as before, and a portal form that no longer shows a field keeps its stored value.
 *
 *     CountryModules::on(CountryModules::PHARMACY);   // true on GB
 *
 * The front end reads the same flags from the shared `country.features` (`hasModule()` in lib/country-modules.ts).
 */
final class CountryModules
{
    /** Deposit return scheme: deposit items, the "Deposit return" tender, bottle deposit receipt lines, return points. */
    public const DEPOSIT_RETURN = 'depositReturn';

    /** UK shop lottery: the product flag, the lottery age rule in pick lists, loyalty points on lottery. */
    public const LOTTERY = 'lottery';

    /** UK alcohol licensing: licensed hours, personal licence holders, licensing wording. */
    public const ALCOHOL_LICENSING = 'alcoholLicensing';

    /** HFSS promotion rules: the product flag and the offer's "Allowed on HFSS food". */
    public const HFSS = 'hfss';

    /** UK vaping duty: the product flag. */
    public const VAPING_DUTY = 'vapingDuty';

    /** The admin master catalogue's "Load starter set" (about 600 UK convenience products). */
    public const UK_STARTER_SET = 'ukStarterSet';

    /** Pharmacy dispensing and medicine classes (the NHS England model). */
    public const PHARMACY = 'pharmacy';

    public const ALL = [
        self::DEPOSIT_RETURN, self::LOTTERY, self::ALCOHOL_LICENSING, self::HFSS, self::VAPING_DUTY, self::UK_STARTER_SET, self::PHARMACY,
    ];

    public static function on(string $module): bool
    {
        return app(Country::class)->feature($module);
    }

    /**
     * The fields of a form whose module is off here (none on GB).
     *
     * @param  array<string, list<string>>  $fieldsByModule  module => fields
     * @return list<string>
     */
    public static function hiddenFields(array $fieldsByModule): array
    {
        $hidden = [];

        foreach ($fieldsByModule as $module => $fields) {
            if (! self::on($module)) {
                array_push($hidden, ...$fields);
            }
        }

        return $hidden;
    }

    /**
     * What a portal form sends for its hidden fields: the stored value of the row being edited, else the new row's
     * default. Merged into the request before validation, so the save writes back what is stored (nothing changes).
     * Empty on GB, where `$stored` is never called.
     *
     *     $this->merge(CountryModules::keep(['alcoholLicensing' => ['is_personal_licence_holder']], fn () => TillUser::find($id)));
     *
     * @param  array<string, list<string>>  $fieldsByModule  module => fields
     * @param  Closure(): ?Model  $stored  the row being edited, null for a new one
     * @param  array<string, mixed>  $defaults  field => value for a new row
     * @return array<string, mixed>
     */
    public static function keep(array $fieldsByModule, Closure $stored, array $defaults = []): array
    {
        $hidden = self::hiddenFields($fieldsByModule);

        if ($hidden === []) {
            return [];
        }

        $stored = $stored();
        $values = [];

        foreach ($hidden as $field) {
            $value = $stored !== null ? $stored->getAttribute($field) : ($defaults[$field] ?? null);
            $values[$field] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $values;
    }
}
