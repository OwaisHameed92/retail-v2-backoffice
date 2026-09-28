<?php

namespace App\Domain\TillData\Generator;

/**
 * Writes the till store's migrations. Column rules (docs/till-data.md): till ULID `string(26)` primary key,
 * `company_id` everywhere, denormalised scope columns, every till column nullable (the validator enforces the
 * schema; the database stays tolerant of additive changes and tombstones), sync columns, no foreign keys.
 *
 * Migrations are additive: a release's migrations create the tables and add the columns and indexes that the
 * releases before it (database/till-schema.json) did not have. Applied migrations are never rewritten.
 */
final class MigrationWriter
{
    public function __construct(private readonly string $contract) {}

    /**
     * Every column of the entity's table, in order: column => Blueprint call without `$table->` and `;`.
     *
     * @return array<string, string>
     */
    public function columns(EntitySpec $entity): array
    {
        $columns = [
            'id' => "string('id', 26)->primary()",
            'company_id' => "string('company_id', 26)",
        ];

        foreach ($entity->scopeColumns() as $column) {
            $columns[$column] = "string('{$column}', 26)->nullable()";
        }

        $reserved = ['row_version', 'created_at', 'updated_at', 'deleted_at', 'synced_at', 'sync_seq', 'extra', 'portal_received_at'];

        if ($entity->isHubOwned()) {
            array_push($reserved, 'hub_version', 'hub_edited_at', 'hub_hash', 'origin_branch_id');
        }

        foreach ($entity->fields as $field) {
            if (in_array($field->column, $reserved, true)) {
                throw new \RuntimeException("{$entity->name}.{$field->name}: column {$field->column} is a sync column. Rename it with a `columns` override.");
            }

            if (! isset($columns[$field->column])) {
                $columns += $this->fieldColumns($field);
            }
        }

        $columns['row_version'] = "unsignedBigInteger('row_version')->default(0)";

        foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
            $columns[$column] = "dateTime('{$column}')->nullable()";
        }

        if ($entity->isHubOwned()) {
            $columns['hub_version'] = "unsignedBigInteger('hub_version')->nullable()";
            $columns['hub_edited_at'] = "dateTime('hub_edited_at')->nullable()";
            $columns['hub_hash'] = "char('hub_hash', 64)->nullable()";
            $columns['origin_branch_id'] = "string('origin_branch_id', 26)->nullable()";
        }

        $columns['synced_at'] = "dateTime('synced_at')->nullable()";
        $columns['sync_seq'] = "unsignedBigInteger('sync_seq')->nullable()";
        $columns['extra'] = "json('extra')->nullable()";
        $columns['portal_received_at'] = "dateTime('portal_received_at')->nullable()";

        return $columns;
    }

    /**
     * Automatic indexes first, then the definitions' extra indexes; duplicates removed. name => columns.
     *
     * @return array<string, list<string>>
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

        $named = [];

        foreach (array_unique($indexes, SORT_REGULAR) as $columns) {
            $named[$this->indexName($entity->table, $columns)] = $columns;
        }

        return $named;
    }

    /**
     * A migration that creates whole tables.
     *
     * @param  list<EntitySpec>  $entities
     */
    public function create(string $title, array $entities): string
    {
        $creates = implode("\n\n", array_map($this->createTable(...), $entities));
        $drops = implode("\n", array_map(
            fn (EntitySpec $e) => "        Schema::dropIfExists('{$e->table}');",
            array_reverse($entities),
        ));
        $names = implode(', ', array_map(fn (EntitySpec $e) => $e->name, $entities));

        return $this->migration("{$title}: {$names}.", $creates, $drops);
    }

    /**
     * A migration that adds columns and indexes to existing tables.
     *
     * @param  array<string, array{columns: array<string, string>, indexes: array<string, list<string>>}>  $additions  table => new columns and indexes
     */
    public function alter(string $title, array $additions): string
    {
        $ups = [];
        $downs = [];

        foreach ($additions as $table => ['columns' => $columns, 'indexes' => $indexes]) {
            $lines = array_map(fn (string $definition) => "\$table->{$definition};", array_values($columns));

            foreach ($indexes as $name => $indexColumns) {
                $lines[] = '$table->index('.Php::literal($indexColumns).', '.Php::literal($name).');';
            }

            $ups[] = $this->tableBlock($table, $lines);

            $undo = [];

            foreach (array_keys($indexes) as $name) {
                $undo[] = '$table->dropIndex('.Php::literal($name).');';
            }

            if ($columns !== []) {
                $undo[] = '$table->dropColumn('.Php::literal(array_keys($columns)).');';
            }

            $downs[] = $this->tableBlock($table, $undo);
        }

        return $this->migration(
            "{$title}: ".count($additions).' tables.',
            implode("\n\n", $ups),
            implode("\n\n", array_reverse($downs)),
        );
    }

    private function migration(string $summary, string $up, string $down): string
    {
        $tag = Php::GENERATED_TAG;

        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * {$summary}
 *
 * {$tag} from {$this->contract}
 * and app/Domain/TillData/definitions.php. Do not edit.
 */
return new class extends Migration
{
    public function up(): void
    {
{$up}
    }

    public function down(): void
    {
{$down}
    }
};

PHP;
    }

    /**
     * @param  list<string>  $lines
     */
    private function tableBlock(string $table, array $lines): string
    {
        $body = implode("\n", array_map(fn (string $l) => '            '.$l, $lines));

        return <<<PHP
        Schema::table('{$table}', function (Blueprint \$table) {
{$body}
        });
PHP;
    }

    private function createTable(EntitySpec $entity): string
    {
        $lines = array_map(fn (string $definition) => "\$table->{$definition};", array_values($this->columns($entity)));
        $lines[] = '';

        foreach ($this->indexes($entity) as $name => $columns) {
            $lines[] = '$table->index('.Php::literal($columns).', '.Php::literal($name).');';
        }

        $body = implode("\n", array_map(fn (string $l) => $l === '' ? '' : '            '.$l, $lines));

        return <<<PHP
        Schema::create('{$entity->table}', function (Blueprint \$table) {
{$body}
        });
PHP;
    }

    /**
     * @return array<string, string>
     */
    private function fieldColumns(FieldSpec $field): array
    {
        $c = $field->column;

        if ($field->type === 'secret') {
            return [
                "{$c}_hash" => "char('{$c}_hash', 64)->nullable()",
                "{$c}_last4" => "string('{$c}_last4', 4)->nullable()",
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

        return [$c => "{$definition}->nullable()"];
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
