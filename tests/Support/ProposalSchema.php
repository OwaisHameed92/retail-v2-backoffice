<?php

namespace Tests\Support;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;

/**
 * JSON Schema validation against contract proposal `docs/contracts/proposals/pda-stock-drafts` (draft 2020-12, opis in
 * spec-compliant mode), or another proposal folder with `errorsIn()` (product-images). The proposal's drafted-entity schemas `$ref` the v1.4.1 entity schemas by relative path, so
 * the whole `docs/contracts` folder is registered under one prefix.
 *
 *     ProposalSchema::payloadErrors($change['payload'], 'StockTake');   // [] when valid
 */
final class ProposalSchema
{
    private const PREFIX = 'https://contracts.sspos.test/';

    public const DIR = 'proposals/pda-stock-drafts';

    private static ?CompliantValidator $validator = null;

    public static function path(string $file = ''): string
    {
        return dirname(__DIR__, 2).'/docs/contracts/'.self::DIR.($file === '' ? '' : '/'.$file);
    }

    /** The proposal schema a pulled entity's payload must match. */
    public static function schemaFor(string $entity): string
    {
        return $entity === 'StockAdjustmentDraft' ? 'schemas/StockAdjustmentDraft.schema.json' : "schemas/{$entity}.hubDrafted.schema.json";
    }

    /**
     * @return list<string>
     */
    public static function payloadErrors(mixed $payload, string $entity): array
    {
        return self::errors($payload, self::schemaFor($entity));
    }

    /**
     * @param  string  $schema  path under the proposal folder
     * @return list<string>
     */
    public static function errors(mixed $data, string $schema): array
    {
        return self::errorsIn(self::DIR, $data, $schema);
    }

    /**
     * The same against another proposal folder (e.g. `proposals/product-images`).
     *
     * @param  string  $schema  path under that folder
     * @return list<string>
     */
    public static function errorsIn(string $dir, mixed $data, string $schema): array
    {
        if (! is_file(dirname(__DIR__, 2).'/docs/contracts/'.$dir.'/'.$schema)) {
            return ["schema {$dir}/{$schema} does not exist"];
        }

        $result = self::validator()->validate(ContractSchema::objects($data), self::PREFIX.$dir.'/'.$schema);

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

    private static function validator(): CompliantValidator
    {
        if (self::$validator === null) {
            self::$validator = new CompliantValidator;
            self::$validator->setMaxErrors(20)->setStopAtFirstError(false);
            self::$validator->resolver()?->registerPrefix(self::PREFIX, dirname(__DIR__, 2).'/docs/contracts/');
        }

        return self::$validator;
    }
}
