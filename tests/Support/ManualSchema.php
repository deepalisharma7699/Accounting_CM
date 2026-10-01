<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The schema that `php artisan migrate` does not build.
 *
 * From §4.6 this repository gains no new migration files: a schema change is
 * SQL an operator runs by hand, in `database/manual/sql`, in a window they
 * chose. That is right for a live installation and it leaves the test suite
 * with a database missing every table added since the rule — which would mean
 * the one kind of change that cannot be verified is the one kind that alters
 * the schema.
 *
 * So the suite runs **the operator's own file**, once, straight after
 * `migrate:fresh`. Not a copy of it: the same bytes, so the shape under test
 * cannot drift from the shape in production. A second declaration of the same
 * table — a migration "just for tests" — is exactly the drift §4.4 is about,
 * and it would be discovered the first time somebody corrected one of the two.
 *
 * ## The transaction has to be closed by hand, and that is not fussiness
 *
 * `RefreshDatabase` opens each test's transaction inside `refreshTestDatabase()`
 * and calls `afterRefreshingDatabase()` — this — immediately afterwards. DDL
 * commits implicitly in MySQL, so simply running the `CREATE TABLE` here would
 * end that transaction without ending the *trait's* belief in it: the first test
 * would then write with autocommit and its rows would survive teardown, into
 * every test after it. A suite that pollutes itself from its first test is the
 * kind of failure that gets blamed on the tests for months.
 *
 * So the transaction is rolled back deliberately, the DDL runs, and it is
 * reopened to the depth it was at. Nothing is lost by the rollback: `setUp()`
 * has run and the test body has not, so it is empty.
 *
 * Once per process, because `migrate:fresh` runs once per process and drops
 * these tables with everything else.
 */
final class ManualSchema
{
    /**
     * Where the operator's SQL lives, relative to the project root.
     */
    private const DIRECTORY = 'database/manual/sql';

    private static bool $applied = false;

    public static function apply(): void
    {
        if (self::$applied) {
            return;
        }

        self::$applied = true;

        $connection = DB::connection();
        $depth = $connection->transactionLevel();

        for ($level = 0; $level < $depth; $level++) {
            $connection->rollBack();
        }

        foreach (self::files() as $path) {
            foreach (self::statementsIn($path) as $statement) {
                /*
                | The operator's verification query is read by a person, and
                | running it here breaks the next test.
                |
                | §4.6 has every manual file end with "the SELECT that shows it
                | worked", which is right: somebody applying this at two in the
                | morning needs to see one row reading `ok`. Nothing reads it
                | here — and `unprepared()` is `PDO::exec()`, which never
                | consumes a result set, so the rows sit on the connection and
                | the *next* query in the process dies with "Cannot execute
                | queries while other unbuffered queries are active".
                |
                | That is once per process, so it cost exactly one test every
                | run — whichever happened to go first, which made it look like
                | a flaky test rather than a fixture leaving the connection
                | dirty. The DDL is what the suite is here for; the commentary
                | and the proof are for the operator.
                */
                if (self::returnsRows($statement)) {
                    continue;
                }

                $connection->unprepared($statement);
            }
        }

        for ($level = 0; $level < $depth; $level++) {
            $connection->beginTransaction();
        }
    }

    /**
     * Does this statement hand back rows rather than change the schema?
     *
     * Only the leading keyword, because that is all that decides it: an
     * `INSERT ... SELECT` is a write and starts with INSERT, and a bare SELECT
     * in one of these files is the operator's check.
     */
    private static function returnsRows(string $statement): bool
    {
        return (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $statement);
    }

    /**
     * The forward files, in name order.
     *
     * `*.rollback.sql` is excluded rather than the forward files being listed:
     * a list is a thing somebody has to remember to extend, and the file they
     * forget would be the table the suite then silently has no coverage of.
     *
     * @return array<int, string>
     */
    private static function files(): array
    {
        $paths = glob(base_path(self::DIRECTORY).'/*.sql') ?: [];

        $paths = array_values(array_filter(
            $paths,
            fn (string $path) => ! str_ends_with($path, '.rollback.sql'),
        ));

        sort($paths);

        return $paths;
    }

    /**
     * One file's statements, with its commentary removed.
     *
     * The files carry a great deal of `--`, because they are read by a person
     * deciding whether to run them at two in the morning. Stripping it here
     * keeps the splitter below honest about what a `;` means.
     *
     * @return array<int, string>
     */
    private static function statementsIn(string $path): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

        $sql = implode("\n", array_filter(
            $lines,
            fn (string $line) => ! str_starts_with(ltrim($line), '--'),
        ));

        return array_values(array_filter(
            array_map(trim(...), explode(';', $sql)),
            fn (string $statement) => $statement !== '',
        ));
    }
}
