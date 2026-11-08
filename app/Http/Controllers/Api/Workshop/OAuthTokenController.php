<?php

namespace App\Http\Controllers\Api\Workshop;

use App\Domain\Workshop\Actions\ExchangeWorkshopToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /oauth/token` for BikeBook (WORKSHOP-BIKEBOOK.md §1.2): client credentials in the body or HTTP Basic, grants
 * `authorization_code` and `refresh_token`. Never cached; errors are RFC 6749 `{error, error_description}`.
 */
class OAuthTokenController extends Controller
{
    public function __invoke(Request $request, ExchangeWorkshopToken $exchange): JsonResponse
    {
        $clientId = $request->getUser() ?? $request->input('client_id');
        $secret = $request->getPassword() ?? $request->input('client_secret');
        $input = $request->only(['grant_type', 'code', 'redirect_uri', 'refresh_token']);

        return response()->json(
            $exchange->handle($input, is_string($clientId) ? $clientId : null, is_string($secret) ? $secret : null),
            200,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }
}
