<?php

namespace Tests\Support;

use App\Domain\Licensing\Signing\Base64Url;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use stdClass;

/**
 * The Pak POS contract (docs/contracts/pak-pos-2026-10-07, the Pakistan till line), for `country-pk` tests: JSON Schema
 * (draft 2020-12) checks against its own copy, kept apart from the UK contract that ContractSchema reads.
 *
 *     PakPosContract::errors($reply, 'licensing/schemas/validate-reply.schema.json'); // [] when valid
 *     PakPosContract::tokenPayload($reply);   // the signed payload of `licenceToken`
 */
final class PakPosContract
{
    public const ROOT = 'docs/contracts/pak-pos-2026-10-07';

    private const PREFIX = 'https://pak-pos-contract.sspos.test/';

    private static ?CompliantValidator $validator = null;

    public static function dir(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/'.self::ROOT.'/docs/web-portal-api'.($path === '' ? '' : '/'.$path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $path): array
    {
        return json_decode((string) file_get_contents(self::dir($path)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function errors(mixed $data, string $schema): array
    {
        if (! is_file(self::dir($schema))) {
            return ["schema {$schema} does not exist"];
        }

        $result = self::validator()->validate(ContractSchema::objects($data), self::PREFIX.$schema);

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = $path.': '.$message;
            }
        }

        return $errors;
    }

    /**
     * The payload of a reply's `licenceToken` (SSPOS1.<payload>.<signature>), as decoded JSON.
     *
     * @param  array<string, mixed>  $reply
     */
    public static function tokenPayload(array $reply): stdClass
    {
        $parts = explode('.', (string) ($reply['licenceToken'] ?? ''));
        $payload = count($parts) === 3 ? json_decode(Base64Url::decode($parts[1]), false) : null;

        return $payload instanceof stdClass ? $payload : new stdClass;
    }

    private static function validator(): CompliantValidator
    {
        if (self::$validator === null) {
            self::$validator = new CompliantValidator;
            self::$validator->setMaxErrors(20)->setStopAtFirstError(false);
            self::$validator->resolver()?->registerPrefix(self::PREFIX, self::dir());
        }

        return self::$validator;
    }
}
