<?php

namespace App\Domain\TillData\Generator;

/**
 * Writes one migration per entity group. Column rules (docs/till-data.md): till ULID `string(26)` primary key,
 * `company_id` everywhere, denormalised scope columns, every till column nullable (the validator enforces the
 * schema; the database stays tolerant of additive changes and tombstones), sync columns, no foreign keys.
 */
final class MigrationWriter
{
    public function __construct(private readonly string $contract) {}

    /**
     * @param  list<EntitySpec>  $entities
     */
    public function write(string $group, array $entities): string
    {
        $creates = implode("\n\n", array_map($this->createTable(...), $entities));
        $drops = implode("\n", array_map(
            fn (EntitySpec $e) => "        Schema::dropIfExists('{$e->table}');",
            array_reverse($entities),
        ));
        $names = implode(', ', array_map(fn (EntitySpec $e) => $e->name, $entities));
        $tag = Php::GENERATED_TAG;

        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * Till entity store, group "{$group}": {$names}.
 *
 * {$tag} from {$this->contract}
 * and app/Domain/TillData/definitions.php. Do not edit.
 */
return new class extends Migration
{
    public function up(): void
    {
{$creates}
    }

    public function down(): void
    {
{$drops}
    }
};

PHP;
    }

    private function createTable(EntitySpec $entity): string
    {
        $lines = [
            "\$table->string('id', 26)->primary();",
            "\$table->string('company_id', 26);",
        ];
        $emitted = ['id', 'company_id'];

        foreach ($entity->scopeColumns() as $column) {
            $lines[] = "\$table->string('{$column}', 26)->nullable();";
            $emitted[] = $column;
        }

        foreach ($entity->fields as $field) {
            if (in_array($field->column, $emitted, true)) {
                continue;
            }

            array_push($lines, ...$this->columnLines($field));
            $emitted[] = $field->column;
        }

        $lines[] = "\$table->unsignedBigInteger('row_version')->default(0);";
        $lines[] = "\$table->dateTime('created_at')->nullable();";
        $lines[] = "\$table->dateTime('updated_at')->nullable();";
        $lines[] = "\$table->dateTime('deleted_at')->nullable();";

        if ($entity->isHubOwned()) {
            $lines[] = "\$table->unsignedBigInteger('hub_version')->nullable();";
            $lines[] = "\$table->dateTime('hub_edited_at')->nullable();";
        }

        $lines[] = "\$table->dateTime('synced_at')->nullable();";
        $lines[] = "\$table->unsignedBigInteger('sync_seq')->nullable();";
        $lines[] = "\$table->json('extra')->nullable();";
        $lines[] = '';

        foreach ($this->indexes($entity) as $columns) {
            $lines[] = '$table->index('.Php::literal($columns).', '.Php::literal($this->indexName($entity->table, $columns)).');';
        }

        $body = implode("\n", array_map(fn (string $l) => $l === '' ? '' : '            '.$l, $lines));

        return <<<PHP
        Schema::create('{$entity->table}', function (Blueprint \$table) {
{$body}
        });
PHP;
    }

    /**
     * @return list<string>
     */
    private function columnLines(FieldSpec $field): array
    {
        $c = $field->column;

        if ($field->type === 'secret') {
            return [
                "\$table->char('{$c}_hash', 64)->nullable();",
                "\$table->string('{$c}_last4', 4)->nullable();",
            ];
        }

        $definition = match ($field->type) {
            'string' => "string('{$c}', {$field->arg})",
            'enum' => "string('{$c}', ".FieldTyper::ENUM_LENGTH.')',
            'text' => "text('{$c}')",
            'longText' => "longText('{$c}')",
            'int' => "integer('{$c}')",
            'bigint' => "bigInteger('{$c}')",
            'money' => "decimal('{$c}', 12, 2)",
            'cost', 'quantity' => "decimal('{$c}', 14, 4)",
            'percent' => "decimal('{$c}', 9, 4)",
            'rate' => "decimal('{$c}', 16, 6)",
            'bool' => "boolean('{$c}')",
            'date' => "date('{$c}')",
            'time' => "time('{$c}')",
            'datetime' => "dateTime('{$c}')",
            'json' => "json('{$c}')",
            default => throw new \RuntimeException("Unknown field type {$field->type}."),
        };

        return ["\$table->{$definition}->nullable();"];
    }

    /**
     * Automatic indexes first, then the definitions' extra indexes; duplicates removed.
     *
     * @return list<list<string>>
     */
    public function indexes(EntitySpec $entity): array
    {
        $indexes = [['company_id', 'updated_at']];

        if ($entity->hasColumn('branch_id')) {
            $indexes[] = ['company_id', 'branch_id'];
        }

        if ($entity->parentColumn() !== null) {
            $indexes[] = [$entity->parentColumn()];
        }

        foreach ($entity->indexes as $columns) {
            foreach ($columns as $column) {
                if ($column !== 'company_id' && ! $entity->hasColumn($column) && ! in_array($column, ['updated_at', 'created_at', 'deleted_at'], true)) {
                    throw new \RuntimeException("{$entity->name}: index column {$column} does not exist.");
                }
            }
            $indexes[] = $columns;
        }

        return array_values(array_unique($indexes, SORT_REGULAR));
    }

    /**
     * Laravel's default name, shortened with a hash when it would pass MySQL's 64-character limit. Index names
     * are global in SQLite, so they always start with the table name.
     *
     * @param  list<string>  $columns
     */
    private function indexName(string $table, array $columns): string
    {
        $name = strtolower($table.'_'.implode('_', $columns).'_index');

        return strlen($name) <= 64 ? $name : substr($table, 0, 48).'_'.substr(md5($name), 0, 8).'_idx';
    }
}
