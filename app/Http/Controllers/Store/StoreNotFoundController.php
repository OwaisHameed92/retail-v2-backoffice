<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Support\Store\StorePages;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public store host's bare root (`https://{ONLINE_STORE_HOST}/`, OS-2): there is no directory of shops, so it
 * answers the store's 404 page instead of the portal.
 */
class StoreNotFoundController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return StorePages::error($request, 404);
    }
}
