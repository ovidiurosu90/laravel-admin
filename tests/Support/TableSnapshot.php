<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;

/**
 * The row counts of every table of some connections, cheap enough to take before and after a whole test run: the
 * highest id of a table with one (an insert that was rolled back never shows, a committed one always does), the row
 * count of a table without. Reads only, on an app of its own booted outside the tests.
 */
final class TableSnapshot
{
    private ?Application $_app = null;

    /**
     * @var array<string, array<string, array{column: ?string, value: int}>> connection => table => reading
     */
    private array $_readings = [];

    /**
     * @param list<string> $connections connection names; the ones the app does not define are left out
     * @param list<string> $ignored     "connection.table" written on purpose outside the tests' transactions
     */
    public function __construct(private readonly array $connections, private readonly array $ignored = [])
    {
    }

    public function take(): void
    {
        $this->_readings = [];
        foreach ($this->_connections() as $name => $connection) {
            foreach ($this->_tables($connection) as $table) {
                $column = $this->_idColumn($connection, $table);

                $this->_readings[$name][$table] = [
                    'column' => $column,
                    'value'  => $this->_read($connection, $table, $column),
                ];
            }
        }
    }

    /**
     * What changed since take(): "connection.table: +N rows" for rows added (and "-N rows" for rows gone from a table
     * without an id), plus the tables created meanwhile.
     *
     * @return list<string>
     */
    public function changes(): array
    {
        $changes = [];
        foreach ($this->_connections() as $name => $connection) {
            foreach ($this->_tables($connection) as $table) {
                $before = $this->_readings[$name][$table] ?? null;
                if ($before === null) {
                    $changes[] = "{$name}.{$table}: new table";
                    continue;
                }

                $added = $this->_added($connection, $table, $before);
                if ($added !== 0) {
                    $changes[] = sprintf('%s.%s: %+d rows', $name, $table, $added);
                }
            }
        }

        return $changes;
    }

    /**
     * @param array{column: ?string, value: int} $before
     */
    private function _added(Connection $connection, string $table, array $before): int
    {
        if ($before['column'] === null) {
            return $this->_read($connection, $table, null) - $before['value'];
        }

        return $connection->table($table)->where($before['column'], '>', $before['value'])->count();
    }

    private function _read(Connection $connection, string $table, ?string $idColumn): int
    {
        $query = $connection->table($table);

        return (int) ($idColumn === null ? $query->count() : $query->max($idColumn));
    }

    private function _idColumn(Connection $connection, string $table): ?string
    {
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            if ($column['name'] === 'id' && $column['auto_increment']) {
                return 'id';
            }
        }

        return null;
    }

    /**
     * The connection's own tables (getTables() can list other databases on the same server too), less the ignored.
     *
     * @return list<string>
     */
    private function _tables(Connection $connection): array
    {
        $database = $connection->getDatabaseName();
        $tables   = [];
        foreach ($connection->getSchemaBuilder()->getTables() as $table) {
            $ignored = in_array($connection->getName() . '.' . $table['name'], $this->ignored, true);
            if (($table['schema'] ?? $database) === $database && !$ignored) {
                $tables[] = $table['name'];
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, Connection>
     */
    private function _connections(): array
    {
        // This snapshot's own app: by the end of the run the global one is the last test's, already flushed
        $app         = $this->_app();
        $connections = [];
        foreach ($this->connections as $name) {
            if ($app['config']->get("database.connections.{$name}") !== null) {
                $connections[$name] = $app['db']->connection($name);
            }
        }

        return $connections;
    }

    private function _app(): Application
    {
        if ($this->_app === null) {
            // The console kernel's bootstrappers less HandleExceptions, so no error handler is left installed
            // outside the tests; the providers add the packages' connections (some need the console request)
            $this->_app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
            $this->_app->bootstrapWith([
                LoadEnvironmentVariables::class, LoadConfiguration::class, RegisterFacades::class,
                SetRequestForConsole::class, RegisterProviders::class, BootProviders::class,
            ]);
        }

        return $this->_app;
    }
}
