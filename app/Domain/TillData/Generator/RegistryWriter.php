<?php

namespace App\Domain\TillData\Generator;

/**
 * Writes EntityRegistry: entity name => model, table, ownership, scope, parent, immutability and the column map
 * (till field => column, type, nullable, arg). The applier and the pull feed read only this.
 */
final class RegistryWriter
{
    private const ENUM_NS = 'App\\Domain\\TillData\\Enums\\';

    public function __construct(private readonly string $contract) {}

    /**
     * @param  array<string, EntitySpec>  $entities
     */
    public function write(array $entities): string
    {
        $imports = ['App\\Domain\\TillData\\Registry\\EntityDefinition', 'RuntimeException'];
        $entries = [];

        foreach ($entities as $entity) {
            $imports[] = ModelWriter::modelClass($entity);
            $entries[] = $this->entry($entity, $entities, $imports);
        }

        $tag = Php::GENERATED_TAG;

        return "<?php\n\nnamespace App\\Domain\\TillData;\n\n".Php::imports($imports)."\n\n"
            ."/**\n * Every till entity the portal stores ({$this->contract}).\n *\n"
            ." * {$tag} from the entity schemas, samples/ownership.json\n"
            ." * and app/Domain/TillData/definitions.php. Do not edit.\n *\n"
            ." * Field entries: column, type (string|text|longText|int|bigint|money|cost|quantity|percent|rate|bool|date|time|\n"
            ." * datetime|enum|json|secret), nullable, arg (varchar length or enum class).\n */\n"
            ."final class EntityRegistry\n{\n"
            ."    public const ENTITIES = [\n"
            .implode('', $entries)
            ."    ];\n\n"
            ."    /** @var array<string, EntityDefinition> */\n"
            ."    private static array \$definitions = [];\n\n"
            ."    public static function has(string \$entity): bool\n    {\n        return isset(self::ENTITIES[\$entity]);\n    }\n\n"
            ."    public static function get(string \$entity): EntityDefinition\n    {\n"
            ."        if (! isset(self::ENTITIES[\$entity])) {\n"
            ."            throw new RuntimeException(\"Unknown till entity [{\$entity}].\");\n        }\n\n"
            ."        return self::\$definitions[\$entity] ??= EntityDefinition::fromArray(\$entity, self::ENTITIES[\$entity]);\n    }\n\n"
            ."    /**\n     * @return list<string>\n     */\n"
            ."    public static function names(): array\n    {\n        return array_keys(self::ENTITIES);\n    }\n"
            ."}\n";
    }

    /**
     * @param  array<string, EntitySpec>  $all
     * @param  list<string>  $imports
     */
    private function entry(EntitySpec $entity, array $all, array &$imports): string
    {
        $i = '        ';
        $parent = 'null';

        if ($entity->parentEntity !== null) {
            $p = $all[$entity->parentEntity];
            $parent = Php::literal([
                'entity' => $p->name,
                'column' => (string) $entity->parentColumn(),
                'table' => $p->table,
                'scope' => $p->scope,
            ]);
        }

        $children = [];

        foreach ($all as $child) {
            if ($child->parentEntity === $entity->name && $child->scope === 'child') {
                $children[] = $child->name;
            }
        }

        $lines = [
            "'model' => ".class_basename(ModelWriter::modelClass($entity)).'::class,',
            "'table' => ".Php::literal($entity->table).',',
            "'ownership' => ".Php::literal($entity->ownership).',',
            "'scope' => ".Php::literal($entity->scope).',',
            "'scopeColumns' => ".Php::literal($entity->scopeColumns()).',',
            "'tenancy' => ".Php::literal($entity->tenancy).',',
            "'parent' => {$parent},",
            "'children' => ".Php::literal($children).',',
            "'derived' => ".Php::literal($entity->derived).',',
            "'dropped' => ".Php::literal($entity->dropped).',',
            "'immutable' => ".$this->immutable($entity, $all).',',
            "'tillFields' => ".Php::literal($entity->tillFields).',',
            "'fields' => [",
        ];

        foreach ($entity->fields as $field) {
            $arg = Php::literal($field->arg);

            if ($field->type === 'enum') {
                $imports[] = self::ENUM_NS.$field->arg;
                $arg = $field->arg.'::class';
            }

            $lines[] = '    '.Php::literal($field->name)." => ['column' => ".Php::literal($field->column)
                .", 'type' => ".Php::literal($field->type).", 'nullable' => ".Php::literal($field->nullable)
                .", 'arg' => {$arg}],";
        }

        $lines[] = '],';

        return "{$i}".Php::literal($entity->name)." => [\n"
            .implode('', array_map(fn (string $l) => "{$i}    {$l}\n", $lines))
            ."{$i}],\n";
    }

    /**
     * @param  array<string, EntitySpec>  $all
     */
    private function immutable(EntitySpec $entity, array $all): string
    {
        if ($entity->immutable === null) {
            return 'null';
        }

        $whenParent = null;

        if ($entity->immutable['whenParent'] !== null) {
            $parent = $all[(string) $entity->parentEntity];
            $whenParent = [];

            foreach ($entity->immutable['whenParent'] as $field => $values) {
                $column = $parent->field($field)->column ?? throw new \RuntimeException("{$entity->name}: parent has no field {$field}.");
                $whenParent[$column] = $values;
            }
        }

        $when = fn (?array $condition) => $condition === null ? 'null' : '['.implode(', ', array_map(
            fn ($column, $values) => Php::literal($column).' => '.Php::literal($values),
            array_keys($condition),
            $condition,
        )).']';

        return "['when' => ".$when($entity->immutable['when'])
            .", 'whenParent' => ".$when($whenParent)
            .", 'always' => ".Php::literal($entity->immutable['always'])
            .", 'mutable' => ".Php::literal($entity->immutable['mutable']).']';
    }
}
