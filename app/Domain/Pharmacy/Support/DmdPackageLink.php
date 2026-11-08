<?php

namespace App\Domain\Pharmacy\Support;

use App\Domain\Pharmacy\Models\DmdRelease;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The zip's `downloadUrl` (W4): `https://<portal>/api/v1/pharmacy/dmd/packages/<archiveFileName>?expires=…&signature=…`,
 * signed over the path and query only (a relative signature, so http behind a TLS proxy and the https link agree),
 * valid DmdSwitch::linkHours() (≥ 24 h) from when the manifest hands it out. The till may send no headers for it;
 * nothing secret is in the URL (the signature is an HMAC of APP_KEY, not a key), and any other query member breaks the
 * signature.
 */
final class DmdPackageLink
{
    public const ROUTE = 'api.pharmacy.dmd.package';

    public static function for(DmdRelease $release, ?CarbonImmutable $now = null): string
    {
        $expires = ($now ?? CarbonImmutable::now())->addHours(DmdSwitch::linkHours());
        $path = URL::temporarySignedRoute(self::ROUTE, $expires, ['archive' => $release->archive_file_name], false);

        return DmdSwitch::publicBase().$path;
    }

    public static function valid(Request $request): bool
    {
        return $request->hasValidRelativeSignature();
    }
}
