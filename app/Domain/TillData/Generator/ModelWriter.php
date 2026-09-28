<?php

namespace App\Domain\TillData\Generator;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Shared\Casts\QuantityCast;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\TillData\Casts\RateCast;
use App\Domain\TillData\Casts\TillEnumCast;
use App\Domain\TillData\Concerns\HubOwnedRow;
use App\Domain\TillData\Concerns\ScopedToBranch;
use App\Domain\TillData\Concerns\ScopedToRegister;
use App\Domain\TillData\Concerns\TillOwnedRow;
use Illuminate\Support\Str;

/**
 * Writes one final Eloquent model per till entity (not Company/Branch/Register: those are module 1.2's models).
 * Behaviour comes only from traits: BelongsToCompany (tenant scope), SoftDeletes, TillOwnedRow (read-only) or
 * HubOwnedRow, ScopedToBranch/ScopedToRegister and the hand-written traits listed in definitions.php.
 */
final class ModelWriter
{
    private const ENUM_NS = 'App\\Domain\\TillData\\Enums\\';

    private const MODEL_NS = 'App\\Domain\\TillData\\Models\\';

    public function __construct(private readonly string $contract) {}

    /**
     * @param  array<string, EntitySpec>  $all
     */
    public function write(EntitySpec $entity, array $all): string
    {
        $imports = [
            BelongsToCompany::class,
            'Carbon\\CarbonImmutable',
            'Illuminate\\Database\\Eloquent\\Model',
            'Illuminate\\Database\\Eloquent\\SoftDeletes',
            UtcDateTimeCast::class,
        ];
        $traits = ['BelongsToCompany', 'SoftDeletes'];

        foreach ($this->behaviourTraits($entity) as $trait) {
            $imports[] = $trait;
            $traits[] = class_basename($trait);
        }

        [$casts, $castImports] = $this->casts($entity);
        [$relations, $relationImports] = $this->relations($entity, $all);
        array_push($imports, ...$castImports, ...$relationImports);
        sort($traits, SORT_STRING | SORT_FLAG_CASE);

        $hidden = $this->hidden($entity);
        $hiddenBlock = $hidden === [] ? '' : "\n    /**\n     * @var list<string>\n     */\n    protected \$hidden = ".Php::literal($hidden).";\n";
        $scope = $entity->scope.' scope'.($entity->parentEntity !== null ? ", parent {$entity->parentEntity}" : '');
        $owner = $entity->isHubOwned() ? 'hub-owned (portal writes, tills apply)' : 'branch-owned (read-only on the portal)';

        return "<?php\n\nnamespace App\\Domain\\TillData\\Models;\n\n".Php::imports($imports)."\n\n"
            ."/**\n * Till entity `{$entity->name}`: {$owner}, {$scope}. Table `{$entity->table}`.\n *\n"
            .' * '.Php::GENERATED_TAG." from\n * {$this->contract}/schemas/entities/{$entity->name}.schema.json and\n"
            ." * app/Domain/TillData/definitions.php. Do not edit: change the definitions or a hand-written trait and regenerate.\n *\n"
            .$this->properties($entity)
            ." */\nfinal class {$entity->class} extends Model\n{\n"
            .'    use '.implode(', ', $traits).";\n\n"
            ."    public const TILL_ENTITY = '{$entity->name}';\n\n"
            ."    public \$incrementing = false;\n\n"
            ."    public \$timestamps = false;\n\n"
            ."    protected \$table = '{$entity->table}';\n\n"
            ."    protected \$keyType = 'string';\n"
            .$hiddenBlock
            ."\n    /**\n     * @return array<string, string>\n     */\n    protected function casts(): array\n    {\n        return [\n"
            .implode('', array_map(fn ($line) => "            {$line}\n", $casts))
            ."        ];\n    }\n"
            .$relations
            ."}\n";
    }

    /**
     * @return list<string>
     */
    private function behaviourTraits(EntitySpec $entity): array
    {
        $traits = [$entity->isHubOwned() ? HubOwnedRow::class : TillOwnedRow::class];

        if (in_array('branch_id', $entity->scopeColumns(), true)) {
            $traits[] = ScopedToBranch::class;
        }

        if (in_array('register_id', $entity->scopeColumns(), true)) {
            $traits[] = ScopedToRegister::class;
        }

        return [...$traits, ...$entity->traits];
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function casts(EntitySpec $entity): array
    {
        $casts = [];
        $imports = [];

        foreach ($entity->fields as $field) {
            [$cast, $import] = match ($field->type) {
                'money' => ['MoneyCast::class', MoneyCast::class],
                'cost', 'quantity', 'percent' => ['QuantityCast::class', QuantityCast::class],
                'rate' => ['RateCast::class', RateCast::class],
                'bool' => ["'boolean'", null],
                'int', 'bigint' => ["'integer'", null],
                'date' => ["'immutable_date:Y-m-d'", null],
                'datetime' => ['UtcDateTimeCast::class', null],
                'json' => ["'array'", null],
                'enum' => ["TillEnumCast::class.':'.{$field->arg}::class", TillEnumCast::class],
                default => [null, null],
            };

            if ($cast === null) {
                continue;
            }

            if ($import !== null) {
                $imports[] = $import;
            }

            if ($field->type === 'enum') {
                $imports[] = self::ENUM_NS.$field->arg;
            }

            $casts[] = "'{$field->column}' => {$cast},";
        }

        $casts[] = "'row_version' => 'integer',";
        $casts[] = "'created_at' => UtcDateTimeCast::class,";
        $casts[] = "'updated_at' => UtcDateTimeCast::class,";
        $casts[] = "'deleted_at' => UtcDateTimeCast::class,";

        if ($entity->isHubOwned()) {
            $casts[] = "'hub_version' => 'integer',";
            $casts[] = "'hub_edited_at' => UtcDateTimeCast::class,";
        }

        $casts[] = "'synced_at' => UtcDateTimeCast::class,";
        $casts[] = "'sync_seq' => 'integer',";
        $casts[] = "'extra' => 'array',";
        $casts[] = "'portal_received_at' => UtcDateTimeCast::class,";

        return [$casts, $imports];
    }

    private function properties(EntitySpec $entity): string
    {
        $lines = [' * @property string $id', ' * @property string $company_id'];
        $done = ['id', 'company_id'];

        foreach ($entity->scopeColumns() as $column) {
            $lines[] = " * @property string|null \${$column}";
            $done[] = $column;
        }

        foreach ($entity->fields as $field) {
            if (in_array($field->column, $done, true)) {
                continue;
            }

            if ($field->type === 'secret') {
                $lines[] = " * @property string|null \${$field->column}_hash";
                $lines[] = " * @property string|null \${$field->column}_last4";

                continue;
            }

            $type = str_replace('\\Carbon\\CarbonImmutable', 'CarbonImmutable', $field->phpType());
            $lines[] = " * @property {$type} \${$field->column}";
        }

        $lines[] = ' * @property int $row_version';
        $lines[] = ' * @property CarbonImmutable|null $created_at';
        $lines[] = ' * @property CarbonImmutable|null $updated_at';
        $lines[] = ' * @property CarbonImmutable|null $deleted_at';

        if ($entity->isHubOwned()) {
            $lines[] = ' * @property int|null $hub_version';
            $lines[] = ' * @property CarbonImmutable|null $hub_edited_at';
            $lines[] = ' * @property string|null $hub_hash';
            $lines[] = ' * @property string|null $origin_branch_id';
        }

        $lines[] = ' * @property CarbonImmutable|null $synced_at';
        $lines[] = ' * @property int|null $sync_seq';
        $lines[] = ' * @property array<string, mixed>|null $extra';
        $lines[] = ' * @property CarbonImmutable|null $portal_received_at';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, EntitySpec>  $all
     * @return array{0: string, 1: list<string>}
     */
    private function relations(EntitySpec $entity, array $all): array
    {
        $code = '';
        $imports = [];

        if ($entity->parentEntity !== null) {
            $parent = $all[$entity->parentEntity];
            $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo';
            $code .= $this->relation(
                Str::camel($parent->class),
                'BelongsTo',
                $parent->class,
                "belongsTo({$parent->class}::class, '{$entity->parentColumn()}')",
            );
        }

        foreach ($all as $child) {
            if ($child->parentEntity === $entity->name && ! $child->tenancy) {
                $imports[] = 'Illuminate\\Database\\Eloquent\\Relations\\HasMany';
                $code .= $this->relation(
                    Str::camel(Str::pluralStudly($child->class)),
                    'HasMany',
                    $child->class,
                    "hasMany({$child->class}::class, '{$child->parentColumn()}')",
                );
            }
        }

        return [$code, $imports];
    }

    private function relation(string $name, string $type, string $related, string $call): string
    {
        return "\n    /**\n     * @return {$type}<{$related}, \$this>\n     */\n"
            ."    public function {$name}(): {$type}\n    {\n        return \$this->{$call};\n    }\n";
    }

    /**
     * @return list<string>
     */
    private function hidden(EntitySpec $entity): array
    {
        $hidden = $entity->hidden;

        foreach ($entity->fields as $field) {
            if ($field->type === 'secret') {
                $hidden[] = $field->column.'_hash';
            }
        }

        return $hidden;
    }

    public static function modelClass(EntitySpec $entity): string
    {
        return $entity->tenancyModel ?? self::MODEL_NS.$entity->class;
    }
}
