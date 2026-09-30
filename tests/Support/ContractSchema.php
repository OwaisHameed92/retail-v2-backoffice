<?php

namespace Tests\Support;

use App\Domain\Sync\Support\PullPayload;
use App\Domain\TillData\EntityRegistry;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use stdClass;

/**
 * JSON Schema (draft 2020-12) validation against the EPOS contract v1.4.1 (module 2.6), with opis/json-schema in
 * spec-compliant mode. Every schema is loaded by its path under `docs/web-portal-api/`, so the licensing schemas'
 * relative `$ref`s (`common.schema.json#/$defs/…`, `../../schemas/error-reply.schema.json`) resolve across files.
 *
 *     ContractSchema::errors($data, 'schemas/push-reply.schema.json');            // [] when valid
 *     ContractSchema::errors($data, 'licensing/schemas/validate-reply.schema.json');
 *     ContractSchema::pullErrors($reply);   // pull-reply + every payload against schemas/entities/<entity>
 *
 * Data may be decoded arrays or objects; it is re-read as JSON objects so `{}` and `[]` stay apart.
 */
final class ContractSchema
{
    public const ROOT = 'docs/contracts/portal-api-v1.4.1/docs/web-portal-api';

    private const PREFIX = 'https://contract.sspos.test/';

    private static ?CompliantValidator $validator = null;

    /** @var array<string, stdClass> entity => its schema with derived members optional */
    private static array $relaxed = [];

    public static function dir(string $path = ''): string
    {
        // Not base_path(): datasets list the samples before the application boots.
        return dirname(__DIR__, 2).'/'.self::ROOT.($path === '' ? '' : '/'.$path);
    }

    /**
     * @param  string  $schema  path under docs/web-portal-api, e.g. `schemas/hello-reply.schema.json`
     * @return list<string> errors, empty when valid
     */
    public static function errors(mixed $data, string $schema): array
    {
        if (! is_file(self::dir($schema))) {
            return ["schema {$schema} does not exist"];
        }

        $result = self::validator()->validate(self::objects($data), self::PREFIX.$schema);

        return $result->isValid() ? [] : self::format($result->error());
    }

    /**
     * A pull reply: the envelope schema, then each row's payload against its entity schema (§5, §13).
     *
     * @return list<string>
     */
    public static function pullErrors(mixed $reply): array
    {
        $reply = self::objects($reply);
        $errors = self::errors($reply, 'schemas/pull-reply.schema.json');

        foreach ($reply instanceof stdClass && is_array($reply->changes ?? null) ? $reply->changes : [] as $i => $change) {
            $errors = [...$errors, ...self::changeErrors($change, "changes[{$i}]", derivedOptional: true)];

            // ANSWERS-2026-09-30-portal point 1: a keyed row always carries its payload, `D` included (the till
            // finds its row by the payload's keys).
            if ($change instanceof stdClass && in_array($change->entity ?? null, ['Setting', 'RolePermission'], true) && ! (($change->payload ?? null) instanceof stdClass)) {
                $errors[] = "changes[{$i}]: {$change->entity} ".($change->op ?? '?').' without a payload';
            }
        }

        return $errors;
    }

    /**
     * One envelope (both directions): sync-change, and its payload against the entity's schema when it has one.
     *
     * @return list<string>
     */
    public static function changeErrors(mixed $change, string $at = 'change', bool $derivedOptional = false): array
    {
        $change = self::objects($change);
        $errors = array_map(fn ($e) => "{$at}: {$e}", self::errors($change, 'schemas/sync-change.schema.json'));
        $entity = $change instanceof stdClass && is_string($change->entity ?? null) ? $change->entity : null;

        // §12: a WebOrder (portal → till message, no table) has its own schema.
        if ($entity === 'WebOrder' && ($change->payload ?? null) !== null) {
            foreach (self::errors($change->payload, 'schemas/web-order.schema.json') as $error) {
                $errors[] = "{$at} WebOrder payload: {$error}";
            }
        } elseif ($entity !== null && ($change->payload ?? null) !== null && preg_match('/^[A-Za-z]+$/', $entity) === 1
            && is_file(self::dir("schemas/entities/{$entity}.schema.json"))) {
            $payloadErrors = $derivedOptional
                ? self::withoutDerivedErrors($change->payload, $entity)
                : self::errors($change->payload, "schemas/entities/{$entity}.schema.json");

            foreach ($payloadErrors as $error) {
                $errors[] = "{$at} {$entity} payload: {$error}";
            }
        }

        return $errors;
    }

    /**
     * A payload the portal sends: the entity schema with the members the till computes (Money objects, navigation
     * collections, status flags: EntityRegistry `derived`) made optional, because contract §6 says derived members
     * "may appear; the till ignores them when reading" and the portal does not store them (docs/DECISIONS.md).
     * Every member that is sent is still checked against its schema.
     *
     * @return list<string>
     */
    public static function withoutDerivedErrors(mixed $payload, string $entity): array
    {
        if (! isset(self::$relaxed[$entity])) {
            $schema = json_decode((string) file_get_contents(self::dir("schemas/entities/{$entity}.schema.json")), false, 512, JSON_THROW_ON_ERROR);
            // Members the portal omits while blank (ANSWERS-2026-09-29-b A.1: a missing member keeps the till's value).
            $derived = [...(EntityRegistry::has($entity) ? EntityRegistry::get($entity)->derived : []), ...(PullPayload::KEPT_WHEN_BLANK[$entity] ?? [])];
            unset($schema->{'$id'});
            $schema->required = array_values(array_diff($schema->required ?? [], $derived));
            self::$relaxed[$entity] = $schema;
        }

        $result = self::validator()->validate(self::objects($payload), self::$relaxed[$entity]);

        return $result->isValid() ? [] : self::format($result->error());
    }

    /** Decoded JSON as objects (json_decode($json) style), whatever shape it came in. */
    public static function objects(mixed $data): mixed
    {
        if ($data instanceof stdClass || is_scalar($data) || $data === null) {
            return $data;
        }

        return json_decode((string) json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
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

    /**
     * @return list<string>
     */
    private static function format(?ValidationError $error): array
    {
        if ($error === null) {
            return ['invalid'];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($error, true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = '$'.($path === '/' ? '' : str_replace('/', '.', (string) $path)).': '.$message;
            }
        }

        return $errors;
    }
}
