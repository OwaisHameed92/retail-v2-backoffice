<?php

namespace App\Domain\ShopSettings\Support;

use App\Domain\Salon\Support\SalonShops;
use App\Domain\Shared\Country\CountryModules;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillSetting;

/**
 * The shop-type sections of the Till settings page (till 0.1.85, W1; `app/Domain/ShopSettings/verticals.php`),
 * read-only and only for the matching shop, so a grocery sees none of them:
 *
 * - Pharmacy: the business type is Pharmacy, or a till sent `vertical.pharmacy` = true or `setup.business_type` =
 *   Pharmacy (PORTAL-CHANGES-pharmacy item 5). Off where the country profile hides the pharmacy module.
 * - Bike workshop: a till sent `integration.workshop` = true or any `workshop.*` setting (WORKSHOP-BIKEBOOK.md).
 * - Shop type: a till sent `setup.business_type` or a `vertical.*` key.
 * - Salon / Barber (salon pack, W2; `app/Domain/ShopSettings/salon.php`): the business type is Salon, or a till sent
 *   `vertical.salon` = true or `setup.business_type` = Salon (SalonShops).
 *
 * Call inside the company scope (TillSetting is a tenant model).
 *
 * @phpstan-import-type Section from SettingCatalogue
 * @phpstan-import-type Definition from SettingCatalogue
 */
final class VerticalSettings
{
    /** @var array<string, Section>|null */
    private static ?array $catalogue = null;

    /**
     * Every section, whatever the shop (tests, key checks).
     *
     * @return array<string, Section>
     */
    public static function catalogue(): array
    {
        /** @var array<string, Section> $sections */
        $sections = self::$catalogue ??= [...require dirname(__DIR__).'/verticals.php', ...require dirname(__DIR__).'/salon.php'];

        return $sections;
    }

    /**
     * The sections this business shows, every setting marked read-only.
     *
     * @return array<string, Section>
     */
    public static function sections(Company $company): array
    {
        $shown = [];

        foreach (self::catalogue() as $id => $section) {
            if (! self::applies($id, $company)) {
                continue;
            }

            $section['settings'] = array_map(fn (array $definition) => [...$definition, 'readOnly' => true], $section['settings']);
            $shown[$id] = $section;
        }

        return $shown;
    }

    /**
     * @return array<string, Definition>
     */
    public static function all(Company $company): array
    {
        $sections = self::sections($company);

        return $sections === [] ? [] : array_merge(...array_values(array_map(fn (array $section) => $section['settings'], $sections)));
    }

    public static function isPharmacy(Company $company): bool
    {
        if (! CountryModules::on(CountryModules::PHARMACY)) {
            return false;
        }

        return $company->business_type === BusinessType::Pharmacy
            || self::tillSent(fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($k) => $k->where('setting_key', 'vertical.pharmacy')->where('value', 'true'))
                ->orWhere(fn ($k) => $k->where('setting_key', 'setup.business_type')->where('value', 'Pharmacy'))));
    }

    public static function isWorkshop(): bool
    {
        return self::tillSent(fn ($q) => $q->where(fn ($w) => $w
            ->where(fn ($k) => $k->where('setting_key', 'integration.workshop')->where('value', 'true'))
            ->orWhere('setting_key', 'like', 'workshop.%')));
    }

    private static function applies(string $section, Company $company): bool
    {
        return match ($section) {
            'pharmacy' => self::isPharmacy($company),
            'workshop' => self::isWorkshop(),
            'salon', 'salonDiary', 'salonMessages', 'salonOnline' => SalonShops::anySalon($company->id),
            'shopType' => self::tillSent(fn ($q) => $q->where(fn ($w) => $w->where('setting_key', 'setup.business_type')->orWhere('setting_key', 'like', 'vertical.%'))),
            default => false,
        };
    }

    /** A live (not removed) setting row of this business matches. */
    private static function tillSent(callable $where): bool
    {
        return $where(TillSetting::query()->whereIn('scope', ['company', 'branch']))->exists();
    }
}
