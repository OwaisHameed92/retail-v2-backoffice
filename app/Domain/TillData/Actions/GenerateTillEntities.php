<?php

namespace App\Domain\TillData\Actions;

use App\Domain\TillData\Generator\EnumWriter;
use App\Domain\TillData\Generator\MigrationPlanner;
use App\Domain\TillData\Generator\MigrationWriter;
use App\Domain\TillData\Generator\ModelWriter;
use App\Domain\TillData\Generator\Php;
use App\Domain\TillData\Generator\RegistryWriter;
use App\Domain\TillData\Generator\SchemaCatalog;

/**
 * Generates the till entity store from the contract: additive migrations for the current release
 * (MigrationPlanner), one model per entity, the enums and EntityRegistry. Output is deterministic, so running it twice changes nothing. With $write = false it only
 * reports which files would change (used by `--check` and the idempotency test).
 */
final class GenerateTillEntities
{
    /**
     * @return array{changed: list<string>, deleted: list<string>, unchanged: int}
     */
    public function handle(bool $write = true, ?string $basePath = null): array
    {
        $basePath ??= base_path();
        $catalog = new SchemaCatalog($basePath);
        $files = $this->render($catalog);
        $changed = [];
        $unchanged = 0;

        foreach ($files as $relative => $contents) {
            $path = $basePath.'/'.$relative;

            if (is_file($path) && file_get_contents($path) === $contents) {
                $unchanged++;

                continue;
            }

            $changed[] = $relative;

            if ($write) {
                @mkdir(dirname($path), 0755, true);
                file_put_contents($path, $contents);
            }
        }

        $deleted = $this->staleFiles($basePath, array_keys($files), $catalog);

        if ($write) {
            foreach ($deleted as $relative) {
                unlink($basePath.'/'.$relative);
            }
        }

        return ['changed' => $changed, 'deleted' => $deleted, 'unchanged' => $unchanged];
    }

    /**
     * @return array<string, string> relative path => contents
     */
    public function render(SchemaCatalog $catalog): array
    {
        $contract = $catalog->contract();
        $entities = $catalog->entities();
        $definitions = $catalog->definitions();
        $files = [];

        $stored = [];

        foreach ($definitions['groups'] as $names) {
            foreach ($names as $name) {
                $stored[] = $entities[$name];
            }
        }

        $files = $this->planner($catalog)->plan($stored, $this->lock($catalog->basePath()));

        $models = new ModelWriter($contract);
        $sources = $catalog->files();

        foreach ($entities as $entity) {
            if (! $entity->tenancy) {
                $files["app/Domain/TillData/Models/{$entity->class}.php"] = $models->write($entity, $entities, $sources->schemaSource($entity->name));
            }
        }

        $enums = new EnumWriter($contract);
        $known = array_keys($catalog->knownEnums());

        foreach ($catalog->enums() as $name => $enum) {
            // An enum read from an overlay schema (a salon list, or a list the overlay added values to) names it.
            $from = array_map(fn (string $field) => $sources->schemaSource(strstr($field, '.', true) ?: $field), $enum['fields']);
            $source = array_values(array_diff($from, [$contract]))[0] ?? $contract;
            $files["app/Domain/TillData/Enums/{$name}.php"] = $enums->write($name, $enum, in_array($name, $known, true), $source);
        }

        $files['app/Domain/TillData/EntityRegistry.php'] = (new RegistryWriter($sources->label()))->write($entities, $catalog->localEntities());

        return $files;
    }

    private function planner(SchemaCatalog $catalog): MigrationPlanner
    {
        return new MigrationPlanner(new MigrationWriter($catalog->files()->label()), $catalog->definitions()['release']);
    }

    /**
     * @return array<string, mixed>
     */
    private function lock(string $basePath): array
    {
        $path = $basePath.'/'.MigrationPlanner::LOCK;

        return is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /**
     * Generated files (tagged) in the generated folders that this run did not produce.
     *
     * @param  list<string>  $produced
     * @return list<string>
     */
    private function staleFiles(string $basePath, array $produced, SchemaCatalog $catalog): array
    {
        $candidates = [
            ...glob($basePath.'/app/Domain/TillData/Models/*.php') ?: [],
            ...glob($basePath.'/app/Domain/TillData/Enums/*.php') ?: [],
            ...glob($this->planner($catalog)->currentGlob($basePath)) ?: [],
        ];
        $stale = [];

        foreach ($candidates as $path) {
            $relative = substr($path, strlen($basePath) + 1);

            if (! in_array($relative, $produced, true) && str_contains((string) file_get_contents($path), Php::GENERATED_TAG)) {
                $stale[] = $relative;
            }
        }

        return $stale;
    }
}
