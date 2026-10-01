<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PDO;

/**
 * Migrate once per migration set, not once per test process (test speed, 2026-10-01).
 *
 * RefreshDatabase on SQLite `:memory:` runs all migrations in every test process (one per paratest worker). This
 * class runs them once, dumps the resulting database (schema in creation order plus every row the migrations
 * inserted) to `storage/framework/testing/sqlite-schema-<hash>.sql`, and later processes load that file into their
 * in-memory database with one `exec()`. The hash covers every migration file's name and content, so adding or
 * editing a migration rebuilds the dump on the next run; nothing is committed. MySQL runs (phpunit.mysql.xml) and
 * file databases are untouched and migrate as before.
 */
final class SqliteTestSchema
{
    /** Bump when the dump format changes. */
    private const VERSION = 1;

    /**
     * Called right after the test application is created: when the test refreshes an in-memory SQLite database
     * that this process has not migrated yet, load (or build) the dump and mark the database migrated, so
     * RefreshDatabase only opens its transaction.
     */
    public static function prime(Application $app, object $test): void
    {
        if (RefreshDatabaseState::$migrated || ! in_array(RefreshDatabase::class, class_uses_recursive($test), true)) {
            return;
        }

        $name = (string) $app['config']->get('database.default');
        $config = (array) $app['config']->get("database.connections.{$name}");

        if (($config['driver'] ?? null) !== 'sqlite' || ($config['database'] ?? null) !== ':memory:') {
            return;
        }

        /** @var Connection $connection */
        $connection = $app['db']->connection($name);
        $path = self::path($app);

        if (is_file($path)) {
            // Rows are inserted table by table, parents not necessarily first: no foreign key checks while loading.
            $pdo = $connection->getPdo();
            $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec((string) file_get_contents($path));
            $pdo->exec('PRAGMA foreign_keys = '.$foreignKeys);
        } else {
            $app[Kernel::class]->call('migrate:fresh');
            $app[Kernel::class]->setArtisan(null);
            self::write($path, self::dump($connection->getPdo()));
        }

        RefreshDatabaseState::$inMemoryConnections[$name] = $connection->getPdo();
        RefreshDatabaseState::$migrated = true;
    }

    /** The dump of the current migrations when one has been built (for test subprocesses with their own file DB). */
    public static function existingDump(Application $app): ?string
    {
        return is_file($path = self::path($app)) ? $path : null;
    }

    private static function path(Application $app): string
    {
        $files = glob($app->databasePath('migrations/*.php')) ?: [];
        sort($files);
        $hash = hash_init('sha1');
        hash_update($hash, self::VERSION.'|'.PHP_VERSION.'|');

        foreach ($files as $file) {
            hash_update($hash, basename($file).'|'.hash_file('sha1', $file).'|');
        }

        return $app->storagePath('framework/testing/sqlite-schema-'.substr(hash_final($hash), 0, 16).'.sql');
    }

    /** The database as SQL: tables, then indexes, triggers and views (creation order), then every row. */
    private static function dump(PDO $pdo): string
    {
        $objects = $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
        $order = ['table' => 0, 'index' => 1, 'trigger' => 2, 'view' => 3];
        usort($objects, fn (array $a, array $b) => $order[$a['type']] <=> $order[$b['type']]);

        $sql = ['BEGIN;'];

        foreach ($objects as $object) {
            $sql[] = $object['sql'].';';
        }

        foreach ($objects as $object) {
            if ($object['type'] !== 'table') {
                continue;
            }

            $table = '"'.str_replace('"', '""', $object['name']).'"';

            foreach ($pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns = implode(', ', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', array_keys($row)));
                $values = implode(', ', array_map(fn ($v) => self::literal($pdo, $v), $row));
                $sql[] = "INSERT INTO {$table} ({$columns}) VALUES ({$values});";
            }
        }

        $sql[] = 'COMMIT;';

        return implode("\n", $sql)."\n";
    }

    private static function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => var_export($value, true),
            is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) => "X'".bin2hex($value)."'",
            default => (string) $pdo->quote((string) $value),
        };
    }

    /** Written beside and renamed, so a parallel worker never reads half a file. */
    private static function write(string $path, string $sql): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, $sql);
        rename($tmp, $path);
    }
}
