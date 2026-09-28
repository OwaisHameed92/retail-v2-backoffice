<?php

namespace Tests\Support;

use stdClass;

/**
 * A small JSON Schema (draft 2020-12) validator for contract tests: type, const, enum, pattern, min/maxLength,
 * minimum/maximum, format date-time, properties, required, additionalProperties, items, uniqueItems, anyOf,
 * oneOf, allOf, if/then/else and file-relative $ref. No JSON Schema package is installed; this covers every
 * keyword the licensing schemas use. Data must be decoded with json_decode($json) (objects as stdClass).
 */
final class JsonSchemaSubset
{
    /** @var array<string, stdClass> */
    private array $files = [];

    public function __construct(private readonly string $directory) {}

    /**
     * @return list<string> Errors; empty when valid.
     */
    public function validate(mixed $data, string $schemaFile): array
    {
        $errors = [];
        $this->check($data, $this->file($schemaFile), $schemaFile, '$', $errors);

        return $errors;
    }

    private function file(string $name): stdClass
    {
        return $this->files[$name] ??= json_decode((string) file_get_contents($this->directory.'/'.$name), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $errors
     */
    private function check(mixed $data, mixed $schema, string $file, string $path, array &$errors): void
    {
        if (is_bool($schema)) {
            if (! $schema) {
                $errors[] = "{$path}: not allowed";
            }

            return;
        }

        if (isset($schema->{'$ref'})) {
            [$refFile, $pointer] = array_pad(explode('#', $schema->{'$ref'}, 2), 2, '');
            $refFile = $refFile === '' ? $file : $refFile;
            $target = $this->file($refFile);

            foreach (array_filter(explode('/', $pointer)) as $segment) {
                $target = $target->{$segment};
            }

            $this->check($data, $target, $refFile, $path, $errors);
        }

        if (isset($schema->type)) {
            $types = (array) $schema->type;
            $ok = false;

            foreach ($types as $type) {
                $ok = $ok || match ($type) {
                    'object' => $data instanceof stdClass,
                    'array' => is_array($data),
                    'string' => is_string($data),
                    'integer' => is_int($data),
                    'number' => is_int($data) || is_float($data),
                    'boolean' => is_bool($data),
                    'null' => $data === null,
                };
            }

            if (! $ok) {
                $errors[] = "{$path}: expected ".implode('|', $types);

                return;
            }
        }

        if (property_exists($schema, 'const') && $data !== $schema->const) {
            $errors[] = "{$path}: must be ".json_encode($schema->const);
        }

        if (isset($schema->enum) && ! in_array($data, $schema->enum, true)) {
            $errors[] = "{$path}: not in enum";
        }

        if (is_string($data)) {
            if (isset($schema->pattern) && preg_match('/'.str_replace('/', '\/', $schema->pattern).'/u', $data) !== 1) {
                $errors[] = "{$path}: does not match {$schema->pattern}";
            }

            if (isset($schema->maxLength) && mb_strlen($data) > $schema->maxLength) {
                $errors[] = "{$path}: longer than {$schema->maxLength}";
            }

            if (isset($schema->minLength) && mb_strlen($data) < $schema->minLength) {
                $errors[] = "{$path}: shorter than {$schema->minLength}";
            }

            if (($schema->format ?? null) === 'date-time' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $data) !== 1) {
                $errors[] = "{$path}: not a date-time";
            }
        }

        if (is_int($data) || is_float($data)) {
            if (isset($schema->minimum) && $data < $schema->minimum) {
                $errors[] = "{$path}: below {$schema->minimum}";
            }

            if (isset($schema->maximum) && $data > $schema->maximum) {
                $errors[] = "{$path}: above {$schema->maximum}";
            }
        }

        if ($data instanceof stdClass) {
            foreach ($schema->required ?? [] as $name) {
                if (! property_exists($data, $name)) {
                    $errors[] = "{$path}: missing {$name}";
                }
            }

            foreach (get_object_vars($data) as $name => $value) {
                if (isset($schema->properties) && property_exists($schema->properties, $name)) {
                    $this->check($value, $schema->properties->{$name}, $file, "{$path}.{$name}", $errors);
                } elseif (property_exists($schema, 'additionalProperties')) {
                    $this->check($value, $schema->additionalProperties, $file, "{$path}.{$name}", $errors);
                }
            }
        }

        if (is_array($data)) {
            if (isset($schema->items)) {
                foreach ($data as $i => $item) {
                    $this->check($item, $schema->items, $file, "{$path}[{$i}]", $errors);
                }
            }

            if (($schema->uniqueItems ?? false) && count(array_unique(array_map('json_encode', $data))) !== count($data)) {
                $errors[] = "{$path}: items not unique";
            }
        }

        foreach ($schema->allOf ?? [] as $sub) {
            $this->check($data, $sub, $file, $path, $errors);
        }

        foreach (['anyOf' => 1, 'oneOf' => 1] as $keyword => $_) {
            if (isset($schema->{$keyword})) {
                $passing = count(array_filter($schema->{$keyword}, fn ($sub) => $this->passes($data, $sub, $file)));

                if ($passing === 0 || ($keyword === 'oneOf' && $passing !== 1)) {
                    $errors[] = "{$path}: fails {$keyword}";
                }
            }
        }

        if (isset($schema->if)) {
            $branch = $this->passes($data, $schema->if, $file) ? ($schema->then ?? null) : ($schema->else ?? null);

            if ($branch !== null) {
                $this->check($data, $branch, $file, $path, $errors);
            }
        }
    }

    private function passes(mixed $data, mixed $schema, string $file): bool
    {
        $errors = [];
        $this->check($data, $schema, $file, '$', $errors);

        return $errors === [];
    }
}
