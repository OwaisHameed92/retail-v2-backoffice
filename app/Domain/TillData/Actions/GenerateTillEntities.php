<?php

namespace App\Domain\TillData\Actions;

use App\Domain\TillData\Generator\EnumWriter;
use App\Domain\TillData\Generator\MigrationWriter;
use App\Domain\TillData\Generator\ModelWriter;
use App\Domain\TillData\Generator\Php;
use App\Domain\TillData\Generator\RegistryWriter;
use App\Domain\TillData\Generator\SchemaCatalog;

/**
 * Generates the till entity store from the contract: one migration per group, one model per entity, the enums
 * and EntityRegistry. Output is deterministic, so running it twice changes nothing. With $write = false it only
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

        $migrations = new MigrationWriter($contract);
        $order = 1;

        foreach ($definitions['groups'] as $group => $names) {
            $groupEntities = array_map(fn (string $name) => $entities[$name], $names);
            $file = sprintf('database/migrations/%s%02d_create_till_%s_tables.php', $definitions['migrationPrefix'], $order++, $group);
            $files[$file] = $migrations->write($group, $groupEntities);
        }

        $models = new ModelWriter($contract);

        foreach ($entities as $entity) {
            if (! $entity->tenancy) {
                $files["app/Domain/TillData/Models/{$entity->class}.php"] = $models->write($entity, $entities);
            }
        }

        $enums = new EnumWriter($contract);
        $known = array_keys($catalog->knownEnums());

        foreach ($catalog->enums() as $name => $enum) {
            $files["app/Domain/TillData/Enums/{$name}.php"] = $enums->write($name, $enum, in_array($name, $known, true));
        }

        $files['app/Domain/TillData/EntityRegistry.php'] = (new RegistryWriter($contract))->write($entities);

        return $files;
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
            ...glob($basePath.'/database/migrations/'.$catalog->definitions()['migrationPrefix'].'*_create_till_*_tables.php') ?: [],
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
