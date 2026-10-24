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
    public const PROPOSAL_CODES = ['proposals/product-images' => 'error-codes.additions.json'];

    /** Proposal calls: path pattern (method + path) => [proposal folder, reply schema]. */
    public const PROPOSAL_REPLY_SCHEMAS = [
        '#^GET api/v1/sync/product-images$#' => ['proposals/product-images', 'schemas/product-image-list-reply.schema.json'],
        '#^PUT api/v1/sync/product-images/[^/]+$#' => ['proposals/product-images', 'schemas/product-image-upload-reply.schema.json'],
        '#^DELETE api/v1/sync/product-images/[^/]+$#' => ['proposals/product-images', 'schemas/product-image-delete-reply.schema.json'],
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

            if (preg_match('#^api/v1/(sync|licence|devices|cloud)(/|$)#', $path) !== 1) {
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
                    return ProposalSchema::errorsIn($dir, $data, $file);
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

        if ($code !== null && ! array_key_exists($code, $known)) {
            $errors[] = "code {$code} is not in error-codes.json";
        } elseif ($code !== null && $known[$code] !== $status) {
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
