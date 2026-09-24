<?php

namespace App\Domain\TillData\Generator;

/**
 * Writes one PHP backed enum per till enum. Values are the till's camelCase strings (samples/enums.json).
 */
final class EnumWriter
{
    public function __construct(private readonly string $contract) {}

    /**
     * @param  array{values: list<string>, fields: list<string>}  $enum
     */
    public function write(string $name, array $enum, bool $known): string
    {
        $source = $known ? 'samples/enums.json' : 'the entity schemas';
        $cases = implode("\n", array_map(
            fn (string $value) => '    case '.Php::caseName($value).' = '.Php::literal($value).';',
            $enum['values'],
        ));
        $fields = implode(', ', $enum['fields']);

        return <<<PHP
<?php

namespace App\\Domain\\TillData\\Enums;

/**
 * Till enum `{$name}` ({$source}). Used by {$fields}.
 *
 * {$this->tag()}
 * from {$this->contract}. Do not edit.
 */
enum {$name}: string
{
{$cases}
}

PHP;
    }

    private function tag(): string
    {
        return Php::GENERATED_TAG;
    }
}
