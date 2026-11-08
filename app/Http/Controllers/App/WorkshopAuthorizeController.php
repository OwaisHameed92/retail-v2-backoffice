<?php

namespace App\Http\Controllers\App;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Licensing\Support\BranchAddOns;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Workshop\Actions\AuthoriseWorkshopClient;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\OAuthClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\WorkshopAuthorizeRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * `GET|POST /oauth/authorize` (WORKSHOP-BIKEBOOK.md §1.2): the shop owner, signed in to the tenant portal, picks the
 * shop BikeBook may use and approves (or declines). Only our client (BikeBook) with one of its registered redirect
 * URIs; anything else shows an error here and never redirects. A shop without the `workshop_integration` add-on
 * cannot be picked ("Contact Switch & Save"). Approving redirects to BikeBook with a one-time code and the `state`.
 */
class WorkshopAuthorizeController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function show(Request $request): Response|HttpResponse
    {
        $error = self::requestError($request->query());

        if ($error === null && $request->query('response_type') !== 'code') {
            return Inertia::location(self::back((string) $request->query('redirect_uri'), ['error' => 'unsupported_response_type', 'state' => $request->query('state')]));
        }

        if ($error === null && OAuthClient::scopes($request->query('scope')) === null) {
            return Inertia::location(self::back((string) $request->query('redirect_uri'), ['error' => 'invalid_scope', 'state' => $request->query('state')]));
        }

        return Inertia::render('app/workshop/authorize', [
            'error' => $error,
            'request' => $error !== null ? null : [
                'clientId' => (string) $request->query('client_id'),
                'redirectUri' => (string) $request->query('redirect_uri'),
                'scope' => implode(' ', OAuthClient::scopes($request->query('scope')) ?? []),
                'state' => is_string($request->query('state')) ? $request->query('state') : '',
                'host' => (string) parse_url((string) $request->query('redirect_uri'), PHP_URL_HOST),
            ],
            'scopes' => OAuthClient::scopes($request->query('scope')) ?? [],
            'business' => $this->tenancy->require()->name,
            'shops' => $error !== null ? [] : $this->shops(),
        ]);
    }

    public function store(WorkshopAuthorizeRequest $request, AuthoriseWorkshopClient $authorise): HttpResponse
    {
        $redirect = (string) $request->input('redirect_uri');
        $state = (string) $request->input('state', '');

        if ($request->input('decision') !== 'approve') {
            return Inertia::location(self::back($redirect, ['error' => 'access_denied', 'state' => $state]));
        }

        $restricted = $this->tenancy->restrictedBranchId();
        $shop = Branch::query()->whereKey((string) $request->input('branch_id'))
            ->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->firstOrFail();
        /** @var User $user */
        $user = $request->user();
        $code = $authorise->handle($shop, $user, $redirect, OAuthClient::scopes($request->input('scope')) ?? []);

        return Inertia::location(self::back($redirect, ['code' => $code, 'state' => $state]));
    }

    /**
     * Why the request cannot be shown or answered by redirect (unknown client, unregistered redirect URI), or null.
     *
     * @param  array<mixed>  $params
     */
    public static function requestError(array $params): ?string
    {
        if (! OAuthClient::configured()) {
            return 'BikeBook is not set up on this portal yet. Contact Switch & Save.';
        }

        if (! OAuthClient::isClient($params['client_id'] ?? null)) {
            return 'This link is not from BikeBook. Start the connection again from BikeBook.';
        }

        if (! OAuthClient::allowsRedirect($params['redirect_uri'] ?? null)) {
            return 'This link sends you to an address BikeBook did not register. Start the connection again from BikeBook.';
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shops(): array
    {
        $restricted = $this->tenancy->restrictedBranchId();
        $connections = WorkshopConnection::query()->get()->keyBy('branch_id');

        return Branch::query()->where('is_active', true)->when($restricted !== null, fn ($q) => $q->whereKey($restricted))
            ->orderBy('name')->get()->map(fn (Branch $shop) => [
                'id' => $shop->id,
                'name' => $shop->name,
                'town' => $shop->town,
                'included' => BranchAddOns::has($shop, AddOn::WorkshopIntegration),
                'status' => $connections->get($shop->id)?->status->value ?? 'notConnected',
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function back(string $redirectUri, array $params): string
    {
        $params = array_filter($params, fn ($v) => is_string($v) && $v !== '');

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query($params);
    }
}
