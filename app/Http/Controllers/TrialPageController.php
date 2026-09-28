<?php

namespace App\Http\Controllers;

use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Enums\BusinessType;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /trial` (module 1.10): the hosted trial request form. Public; it posts to the public API with fetch, like
 * the marketing website will, so it doubles as a working example for the website developer.
 */
class TrialPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('trial', [
            'endpoint' => route('api.public.trial-requests.store', absolute: false),
            'turnstileSiteKey' => (string) config('services.turnstile.site_key') ?: null,
            'businessTypes' => BusinessType::options(),
            'maxShops' => Lead::MAX_SHOPS,
            'maxTills' => Lead::MAX_TILLS,
            'supportEmail' => (string) config('sspos.support_email'),
            'supportPhone' => (string) config('sspos.support_phone') ?: null,
        ]);
    }
}
