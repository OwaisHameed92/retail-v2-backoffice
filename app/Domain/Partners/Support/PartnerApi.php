<?php

namespace App\Domain\Partners\Support;

use App\Domain\Shared\Country\Country;

/**
 * The partner onboarding flow is UK only (owner 2026-10-12): the country profile's `features.partnerApi` (GB on, PK
 * off). Off: `/api/partner/v1/*` and the admin partner pages answer 404 (route middleware `country.feature:partnerApi`),
 * the nav items are hidden and no partner webhook is ever made or sent.
 */
final class PartnerApi
{
    public const FEATURE = 'partnerApi';

    public static function enabled(): bool
    {
        return app(Country::class)->feature(self::FEATURE);
    }
}
