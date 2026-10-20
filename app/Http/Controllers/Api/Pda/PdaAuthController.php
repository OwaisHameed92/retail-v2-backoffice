<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\LogInHandheldUser;
use App\Domain\Handheld\Actions\LogOutHandheldUser;
use App\Domain\Handheld\Data\HandheldSession;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaLoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handheld app sign-in (docs/pda-api.md §1): login (public, its own rate limit), me, logout. The plain token is only
 * ever in the login reply, which is never cached.
 */
class PdaAuthController extends Controller
{
    public function login(PdaLoginRequest $request, LogInHandheldUser $login): JsonResponse
    {
        $issued = $login->handle(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request->validated('tenant_id'),
            $request->validated('device_name'),
        );

        return response()->json([
            'success' => true,
            'token' => $issued->plainToken,
            'token_type' => 'Bearer',
            ...HandheldSession::of($issued->user, $issued->company, $issued->shop),
        ])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request, CurrentCompany $tenancy): JsonResponse
    {
        $session = HandheldSession::of(AuthenticateHandheldToken::user($request), $tenancy->require(), AuthenticateHandheldToken::shop($request));

        return response()->json(['success' => true, ...$session]);
    }

    public function logout(Request $request, LogOutHandheldUser $logout): JsonResponse
    {
        $logout->handle(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::token($request));

        return response()->json(['success' => true, 'message' => 'Signed out.']);
    }
}
