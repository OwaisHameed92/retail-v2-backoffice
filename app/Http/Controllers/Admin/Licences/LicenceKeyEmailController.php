<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Licensing\Actions\EmailLicenceKeys;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmailLicenceKeysRequest;
use Illuminate\Http\JsonResponse;

/**
 * "Email this key to the owner" (JSON only; the keys travel in the body).
 */
class LicenceKeyEmailController extends Controller
{
    public function __invoke(EmailLicenceKeysRequest $request, EmailLicenceKeys $emailKeys): JsonResponse
    {
        $owners = $emailKeys->handle($request->keys());

        return response()->json([
            'message' => $owners === 1 ? 'Emailed to the owner.' : "Emailed to {$owners} owners.",
            'owners' => $owners,
        ]);
    }
}
