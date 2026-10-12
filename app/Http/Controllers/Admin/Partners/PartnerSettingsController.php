<?php

namespace App\Http\Controllers\Admin\Partners;

use App\Domain\Partners\Actions\GenerateWebhookSecret;
use App\Domain\Partners\Actions\IssuePartnerKey;
use App\Domain\Partners\Actions\RevokePartnerKey;
use App\Domain\Partners\Actions\SavePartner;
use App\Domain\Partners\Data\PartnerSettingsData;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Partners\PartnerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings → Partners (owner: partners.manage): partners, their webhook URL and signing secret, and their API
 * keys. New keys and secrets answer JSON and exist only in that reply (shown once, never flashed or logged).
 */
class PartnerSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/settings/partners', PartnerSettingsData::all());
    }

    public function store(PartnerRequest $request, SavePartner $save): RedirectResponse
    {
        $partner = $save->handle(null, (string) $request->validated('name'), $request->validated('webhook_url'), $request->boolean('is_active', true), $request->user('admin')?->getKey());

        return back()->with('success', "{$partner->name} added. Create an API key for them next.");
    }

    public function update(PartnerRequest $request, Partner $partner, SavePartner $save): RedirectResponse
    {
        $save->handle($partner, (string) $request->validated('name'), $request->validated('webhook_url'), $request->boolean('is_active', true));

        return back()->with('success', 'Partner saved.');
    }

    public function webhookSecret(Partner $partner, GenerateWebhookSecret $generate): JsonResponse
    {
        return self::noStore(response()->json(['secret' => $generate->handle($partner), 'ending' => $partner->webhook_secret_last4]));
    }

    public function issueKey(Request $request, Partner $partner, IssuePartnerKey $issue): JsonResponse
    {
        $request->validate(['name' => ['nullable', 'string', 'max:80']]);
        [$key, $plain] = $issue->handle($partner, $request->string('name')->toString(), $request->user('admin')?->getKey());

        return self::noStore(response()->json(['key' => $plain, 'id' => $key->id, 'ending' => $key->key_last4]));
    }

    public function revokeKey(Partner $partner, PartnerKey $key, RevokePartnerKey $revoke): RedirectResponse
    {
        $revoke->handle($key);

        return back()->with('success', 'Key revoked. Calls with it are refused from now on.');
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
