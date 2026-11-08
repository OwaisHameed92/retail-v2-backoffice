<?php

namespace Tests\Support;

use App\Domain\Licensing\Signing\Base64Url;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use stdClass;

/**
 * Module 2.6: every reply a till endpoint gives in ANY feature test is checked against the contract after the test
 * (TestCase::tearDown), so timing tests measure the endpoint only.
 *
 * - 2xx: the endpoint's reply schema (pull: the envelope and every payload against its entity schema; licence
 *   replies: the embedded token's payload against licence-token-payload.schema.json).
 * - Anything else: `licensing/schemas/error-reply.schema.json` (the §9 envelope + `details`), and the code must be in
 *   `licensing/samples/error-codes.json` with the same HTTP status, or be listed in PENDING_CODES (docs/DECISIONS.md),
 *   or in a contract proposal's error-codes additions (PROPOSAL_CODES; its calls' replies against its schemas).
 */
final class ContractReplyGuard
{
    /**
     * Our codes the contract does not list yet, pending EPOS confirmation (docs/DECISIONS.md), code => status.
     * Empty since the 0.1.53 pack: `device.token_mismatch` (403, devices/deactivate) is in error-codes.json now.
     *
     * @var array<string, int>
     */
    public const PENDING_CODES = [];

    /** Till endpoints: [method path] => reply schema (`pull` = pull reply with entity payloads). */
    public const REPLY_SCHEMAS = [
        'GET api/v1/sync/hello' => 'schemas/hello-reply.schema.json',
        'POST api/v1/sync/push' => 'schemas/push-reply.schema.json',
        'GET api/v1/sync/pull' => 'pull',
        'POST api/v1/licence/activate' => 'licensing/schemas/licence-activate-reply.schema.json',
        'POST api/v1/licence/validate' => 'licensing/schemas/validate-reply.schema.json',
        'POST api/v1/devices/deactivate' => 'licensing/schemas/deactivate-reply.schema.json',
        // Module 2.8.
        'POST api/v1/licence/redeem' => 'licensing/schemas/redeem-reply.schema.json',
        'POST api/v1/cloud/migrate' => 'licensing/schemas/migrate-reply.schema.json',
        'POST api/v1/cloud/migrate/complete' => 'licensing/schemas/migrate-complete-reply.schema.json',
    ];

    /** Contract proposals whose calls the portal answers (switched on in tests): folder => error-codes additions file. */
    public const PROPOSAL_CODES = [
        'proposals/product-images' => 'error-codes.additions.json',
        // W3a: the salon pack's five calls.
        self::SALON => 'error-codes.additions.json',
        // W4: the dm+d manifest (PORTAL-CHANGES-pharmacy.md item 12 as files; the folder's README).
        'proposals/pharmacy-dmd' => 'error-codes.additions.json',
    ];

    /** The salon pack's own folder (salon/README.md §15 codes, salon/schemas/* replies). */
    public const SALON = 'salon-barber-2026-11-08/docs/web-portal-api/salon';

    /** Proposal calls: path pattern (method + path) => [proposal folder, reply schema]. */
    public const PROPOSAL_REPLY_SCHEMAS = [
        '#^GET api/v1/sync/product-images$#' => ['proposals/product-images', 'schemas/product-image-list-reply.schema.json'],
        '#^PUT api/v1/sync/product-images/[^/]+$#' => ['proposals/product-images', 'schemas/product-image-upload-reply.schema.json'],
        '#^DELETE api/v1/sync/product-images/[^/]+$#' => ['proposals/product-images', 'schemas/product-image-delete-reply.schema.json'],
        '#^GET api/v1/pharmacy/dmd/latest$#' => ['proposals/pharmacy-dmd', 'schemas/dmd-manifest-reply.schema.json'],
        // W3a (salon README §4): heartbeat, answer and queue replies have no schema in the pack (`{result}`; their
        // tests check them).
        '#^PUT api/v1/sync/salon/booking-page$#' => [self::SALON, 'schemas/booking-page-reply.schema.json'],
        '#^GET api/v1/sync/salon/queue-joins$#' => [self::SALON, 'schemas/queue-joins-reply.schema.json'],
        '#^PUT api/v1/sync/salon/(heartbeat|queue)$#' => [self::SALON, null],
        '#^POST api/v1/sync/salon/queue-joins/[^/]+/answer$#' => [self::SALON, null],
    ];

    /** Whether `METHOD path` is a call of a contract proposal (PROPOSAL_REPLY_SCHEMAS). */
    public static function isProposalCall(string $what): bool
    {
        foreach (array_keys(self::PROPOSAL_REPLY_SCHEMAS) as $pattern) {
            if (preg_match($pattern, $what) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @var list<array{what: string, status: int, body: string}> */
    private array $replies = [];

    /** @var array<string, int>|null */
    private static ?array $codes = null;

    public static function watch(Application $app): self
    {
        $guard = new self;
        $app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($guard) {
            $path = $event->request->path();

            // The dm+d manifest is a till call too (W4); its zip is not JSON and is checked by its own tests.
            if (preg_match('#^api/v1/(sync|licence|devices|cloud)(/|$)|^api/v1/pharmacy/dmd/latest$#', $path) !== 1) {
                return;
            }

            $body = (string) $event->response->getContent();

            if (str_contains(strtolower((string) $event->response->headers->get('Content-Encoding')), 'gzip')) {
                $body = (string) gzdecode($body);
            }

            $guard->replies[] = ['what' => $event->request->method().' '.$path, 'status' => $event->response->getStatusCode(), 'body' => $body];
        });

        return $guard;
    }

    /**
     * The contract codes, code => HTTP status (`licensing/samples/error-codes.json`) plus the pending ones.
     *
     * @return array<string, int>
     */
    public static function knownCodes(): array
    {
        if (self::$codes === null) {
            $list = json_decode((string) file_get_contents(ContractSchema::dir('licensing/samples/error-codes.json')), true, 512, JSON_THROW_ON_ERROR);
            self::$codes = [...array_column($list, 'status', 'code'), ...self::PENDING_CODES];

            foreach (self::PROPOSAL_CODES as $dir => $file) {
                $added = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/docs/contracts/'.$dir.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
                self::$codes += array_column($added, 'status', 'code');
            }
        }

        return self::$codes;
    }

    /**
     * @return list<string> one line per reply that breaks the contract
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->replies as $i => ['what' => $what, 'status' => $status, 'body' => $body]) {
            foreach (self::check($what, $status, $body) as $error) {
                $violations[] = "reply #{$i} {$what} → {$status}: {$error}";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function check(string $what, int $status, string $body): array
    {
        $data = json_decode($body, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['the body is not JSON'];
        }

        if ($status >= 200 && $status < 300) {
            $schema = self::REPLY_SCHEMAS[$what] ?? null;

            foreach ($schema === null ? self::PROPOSAL_REPLY_SCHEMAS : [] as $pattern => [$dir, $file]) {
                if (preg_match($pattern, $what) === 1) {
                    return $file === null ? [] : ProposalSchema::errorsIn($dir, $data, $file);
                }
            }

            return match ($schema) {
                null => [],   // a route only a test registers (e.g. a middleware probe); every real one is listed
                'pull' => ContractSchema::pullErrors($data),
                default => [...ContractSchema::errors($data, $schema), ...self::tokenErrors($data)],
            };
        }

        $errors = ContractSchema::errors($data, 'licensing/schemas/error-reply.schema.json');
        $code = $data instanceof stdClass && is_string($data->code ?? null) ? $data->code : null;
        $known = self::knownCodes();

        // Salon README §4.1: a salon body that breaks its schema is `422 request.invalid` (`rejectedKey` = the path).
        $salonInvalid = $code === 'request.invalid' && $status === 422 && str_contains($what, ' api/v1/sync/salon/');

        if ($code !== null && ! array_key_exists($code, $known)) {
            $errors[] = "code {$code} is not in error-codes.json";
        } elseif ($code !== null && $known[$code] !== $status && ! $salonInvalid) {
            $errors[] = "code {$code} is HTTP {$known[$code]} in error-codes.json";
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function tokenErrors(mixed $reply): array
    {
        $token = $reply instanceof stdClass && is_string($reply->licenceToken ?? null) ? $reply->licenceToken : null;

        if ($token === null) {
            return [];
        }

        $parts = explode('.', $token);
        $payload = count($parts) === 3 ? json_decode(Base64Url::decode($parts[1]), false) : null;

        if (! $payload instanceof stdClass) {
            return ['licenceToken is not SSPOS1.<payload>.<signature>'];
        }

        return array_map(fn (string $e) => "licenceToken payload: {$e}", ContractSchema::errors($payload, 'licensing/schemas/licence-token-payload.schema.json'));
    }
}
