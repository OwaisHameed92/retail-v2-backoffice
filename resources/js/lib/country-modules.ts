/**
 * UK till modules a country profile can hide (Pakistan plan P10; PHP `App\Domain\Shared\Country\CountryModules`).
 * The shared `country.features` carries a module only where it is off (`pharmacy: false` on PK), so GB pages read
 * exactly as before. Hidden = not shown in the portal; the tills keep the data and a form keeps the stored value.
 * Call these when rendering, never at module load (the profile is set after the first page's modules are imported).
 */
import { country } from '@/lib/country';

export type CountryModule = 'depositReturn' | 'lottery' | 'alcoholLicensing' | 'hfss' | 'vapingDuty' | 'ukStarterSet' | 'pharmacy';

/** True where the module is shown (every module on GB). */
export function hasModule(module: CountryModule): boolean {
    return country().features[module] !== false;
}

/**
 * The till's age rules for a pick list: all of them where the lottery is shown, else without "Lottery (18)"; where
 * the profile limits the rules (Pak POS: None and 18 or over only) just those. The value already chosen always stays
 * (a stored value stays visible and is saved unchanged).
 */
export function visibleAgeRules<T extends { value: string }>(options: T[], current?: string | null): T[] {
    const offered = country().ageRules;
    const limited = offered ? options.filter((option) => offered.includes(option.value) || option.value === current) : options;

    return hasModule('lottery') ? limited : limited.filter((option) => option.value !== 'lottery18' || option.value === current);
}
