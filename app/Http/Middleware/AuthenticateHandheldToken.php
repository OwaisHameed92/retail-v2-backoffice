<?php

namespace App\Http\Middleware;

use App\Domain\Handheld\Actions\ResolveHandheldToken;
use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Closure;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/pda/*` after login (docs/pda-api.md): `Authorization: Bearer <token>` only — a token in the query string is
 * never read (`bearerToken()` reads the header). Sets the current company to the user's business, limited to the
 * user's shop (CurrentCompany `restrictedBranchId`), and the `handheld` guard's user, so audit entries written during
 * the call name the handheld user. Controllers read them with `user()`, `token()` and `shop()`.
 */
class AuthenticateHandheldToken
{
    public const USER = 'handheld.user';

    public const TOKEN = 'handheld.token';

    public const SHOP = 'handheld.shop';

    public function __construct(
        private readonly ResolveHandheldToken $resolve,
        private readonly CurrentCompany $tenancy,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        [$token, $user, $company, $shop] = $this->resolve->handle($request->bearerToken());

        $request->attributes->set(self::TOKEN, $token);
        $request->attributes->set(self::USER, $user);
        $request->attributes->set(self::SHOP, $shop);

        $guard = $this->auth->guard('handheld');
        [$previous, $previousRole, $previousShop] = [$this->tenancy->get(), $this->tenancy->role(), $this->tenancy->restrictedBranchId()];
        $this->tenancy->set($company, null, $shop->id);

        if ($guard instanceof RequestGuard) {
            $guard->setUser($user);
        }

        try {
            return $next($request);
        } finally {
            // Never carried into a later request of a long-lived process (or a test's next call).
            if ($guard instanceof RequestGuard) {
                $guard->forgetUser();
            }

            $previous === null ? $this->tenancy->forget() : $this->tenancy->set($previous, $previousRole, $previousShop);
        }
    }

    public static function user(Request $request): HandheldUser
    {
        $user = $request->attributes->get(self::USER);

        return $user instanceof HandheldUser ? $user : throw new \LogicException('The route is not behind AuthenticateHandheldToken.');
    }

    public static function token(Request $request): HandheldToken
    {
        $token = $request->attributes->get(self::TOKEN);

        return $token instanceof HandheldToken ? $token : throw new \LogicException('The route is not behind AuthenticateHandheldToken.');
    }

    public static function shop(Request $request): Branch
    {
        $shop = $request->attributes->get(self::SHOP);

        return $shop instanceof Branch ? $shop : throw new \LogicException('The route is not behind AuthenticateHandheldToken.');
    }
}
