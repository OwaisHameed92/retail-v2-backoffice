<?php

namespace App\Domain\Handheld\Data;

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * A successful handheld login: the user, their business and shop, the stored token row and the plain token, which
 * goes into the login reply once and nowhere else.
 */
final readonly class IssuedHandheldToken
{
    public function __construct(
        public HandheldUser $user,
        public Company $company,
        public Branch $shop,
        public HandheldToken $token,
        #[\SensitiveParameter] public string $plainToken,
    ) {}
}
