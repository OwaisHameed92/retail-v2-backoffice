<?php

namespace App\Http\Controllers\Api;

use App\Domain\Pharmacy\Actions\OpenDmdPackage;
use App\Domain\Pharmacy\Actions\ShowDmdManifest;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSyncKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The pharmacy dm+d release server (W4, PORTAL-CHANGES-pharmacy.md item 12): `GET pharmacy/dmd/latest` (the
 * manifest, sync key auth) and `GET pharmacy/dmd/packages/{archive}` (the zip, signed link). Switch, auth, limits
 * and signature are the routes' middleware; the work is in ShowDmdManifest and OpenDmdPackage.
 */
class PharmacyDmdController extends Controller
{
    public function latest(Request $request, ShowDmdManifest $manifest): JsonResponse
    {
        return response()->json($manifest->handle(AuthenticateSyncKey::caller($request)));
    }

    public function package(string $archive, OpenDmdPackage $package): StreamedResponse
    {
        return $package->handle($archive);
    }
}
