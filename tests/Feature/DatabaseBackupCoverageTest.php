<?php

declare(strict_types=1);

namespace Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Schema;
use ovidiuro\adminmydata\AdminMyDataServiceProvider;
use Tests\TestCase;

/**
 * Every table must live in exactly one of the two places that keep its data:
 * - backed up in git by the laravel-package-admin-mydata export (database.schema.tables, from adminmydata.php);
 * - copied from production by scripts/sync-prod-db.sh (PROD_SYNC_TABLES in .env), for bulky stats and logs.
 *
 * A new migration that creates a table fails here until the table is added to one of them. A connection the app
 * does not define (myfinance2_mysql without that package) is left out. Read-only: lists the tables of both
 * connections, writes nothing.
 */
class DatabaseBackupCoverageTest extends TestCase
{
    /**
     * PROD_SYNC_TABLES DB_KEY => database connection.
     */
    private const SYNC_CONNECTIONS = [
        'DB'            => 'mysql',
        'MYFINANCE2_DB' => 'myfinance2_mysql',
    ];

    public function test_every_table_is_backed_up_in_git_or_synced_from_prod(): void
    {
        if (!class_exists(AdminMyDataServiceProvider::class)) {
            $this->markTestSkipped('The ovidiuro/laravel-package-admin-mydata package (git backup) is not installed.');
        }

        $backedUp = $this->_backedUpTables();
        $synced   = $this->_syncedTables();
        $missing  = [];
        $both     = [];

        foreach ($this->_connections() as $dbKey => $connection) {
            foreach ($this->_tables($connection) as $table) {
                $inGit  = in_array($table, $backedUp, true);
                $inSync = in_array($table, $synced[$dbKey] ?? [], true);
                if (!$inGit && !$inSync) {
                    $missing[] = "{$connection}.{$table}";
                } elseif ($inGit && $inSync) {
                    $both[] = "{$connection}.{$table}";
                }
            }
        }

        $this->assertSame([], $missing, 'Tables neither backed up in git (adminmydata.php schema.tables) nor synced'
            . ' from prod (PROD_SYNC_TABLES in .env): ' . implode(', ', $missing));
        $this->assertSame([], $both, 'Tables both backed up in git and synced from prod; keep each in one place: '
            . implode(', ', $both));
    }

    public function test_every_synced_table_exists(): void
    {
        $unknown = [];
        foreach ($this->_syncedTables() as $dbKey => $tables) {
            if (!isset(self::SYNC_CONNECTIONS[$dbKey])) {
                $unknown[] = "{$dbKey} (unknown DB_KEY)";
                continue;
            }
            $connection = $this->_connections()[$dbKey] ?? null;
            if ($connection === null) {
                continue;
            }
            foreach (array_diff($tables, $this->_tables($connection)) as $table) {
                $unknown[] = "{$dbKey}:{$table}";
            }
        }

        $this->assertSame([], $unknown, 'PROD_SYNC_TABLES entries with no such table (sync-prod-db.sh would fail): '
            . implode(', ', $unknown));
    }

    /**
     * SYNC_CONNECTIONS limited to the connections this app defines.
     *
     * @return array<string, string> DB_KEY => database connection
     */
    private function _connections(): array
    {
        return array_filter(self::SYNC_CONNECTIONS, fn (string $connection) => config(
            "database.connections.{$connection}") !== null);
    }

    /**
     * Table names of the connection's own database (getTables() can list other schemas too).
     *
     * @return list<string>
     */
    private function _tables(string $connection): array
    {
        $database = config("database.connections.{$connection}.database");

        return array_values(array_column(array_filter(
            Schema::connection($connection)->getTables(),
            fn (array $table) => ($table['schema'] ?? $database) === $database
        ), 'name'));
    }

    /**
     * Resolved like laravel-package-admin-mydata's ovidiuro\adminmydata\Service\Tables:
     * a model class maps to its table, any other string is the table name.
     *
     * @return list<string>
     */
    private function _backedUpTables(): array
    {
        $tables = [];
        foreach (config('database.schema.tables', []) as $key => $value) {
            $entry = is_string($key) ? $key : $value;
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $tables[] = class_exists($entry) ? (new $entry())->getTable() : $entry;
        }

        return $tables;
    }

    /**
     * Read from .env directly, as scripts/sync-prod-db.sh does.
     *
     * @return array<string, list<string>> DB_KEY => tables
     */
    private function _syncedTables(): array
    {
        $envFile = base_path('.env');
        $env     = is_file($envFile) ? Dotenv::parse((string) file_get_contents($envFile)) : [];
        $raw     = $env['PROD_SYNC_TABLES'] ?? '';
        if (trim($raw) === '') {
            $this->markTestSkipped('PROD_SYNC_TABLES is not set in .env');
        }

        $tables = [];
        foreach (explode(',', $raw) as $pair) {
            [$dbKey, $table] = array_pad(explode(':', trim($pair), 2), 2, '');
            if ($dbKey !== '' && $table !== '') {
                $tables[$dbKey][] = $table;
            }
        }

        return $tables;
    }
}
