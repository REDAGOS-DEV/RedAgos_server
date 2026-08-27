<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Ask the deployed driver's grammar what a query really compiles to.
 *
 * Two writes in inventory rest on a row lock — intake's donation lock and the
 * expiry sweep's re-check — and neither is provable under sqlite :memory:,
 * where lockForUpdate() compiles to nothing at all. Compiling against the
 * pgsql grammar is what catches the refactor that drops a lock while the code
 * still reads fine.
 */
trait CompilesPostgresQueries
{
    /**
     * The queries a callback compiles to on PostgreSQL, without a PostgreSQL.
     *
     * `pretend()` executes nothing and the pgsql connection resolves its PDO
     * lazily, so no server is contacted. The throwaway sqlite handle is there
     * for one reason: a pretended query log substitutes its bindings into the
     * statement it records, and quoting a string is the one step the connection
     * cannot take without a PDO. Nothing is ever run against it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function compiledOnPostgres(callable $callback): array
    {
        $original = config('database.default');

        config(['database.default' => 'pgsql']);

        $connection = DB::connection('pgsql');
        $connection->setPdo(new PDO('sqlite::memory:'));

        try {
            return $connection->pretend($callback);
        } finally {
            config(['database.default' => $original]);
        }
    }
}
