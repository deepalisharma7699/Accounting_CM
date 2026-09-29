<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\ManualSchema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against wiping a real database, then build the tables `migrate`
     * does not build.
     *
     * The suite runs against MySQL (see phpunit.xml) and RefreshDatabase issues
     * `migrate:fresh`, which drops every table. If a stray environment variable
     * ever pointed the tests at the development database, that would destroy it
     * silently — so refuse to run unless the target is clearly a test database.
     *
     * ## Why the manual schema is applied here and not from the trait's hook
     *
     * `RefreshDatabase` offers `afterRefreshingDatabase()`, which is where this
     * obviously belongs — and it cannot be used from this class. A trait method
     * overrides one inherited from a parent, so every test class that writes
     * `use RefreshDatabase` would take the trait's empty version in preference
     * to anything declared here, and the tables would silently never be built.
     * PHP does not even get that far: the trait's method carries no return type
     * and a parent declaring `: void` is a fatal incompatibility at class
     * definition, so the whole suite refuses to boot.
     *
     * `parent::setUp()` runs `setUpTraits()`, which refreshes the database and
     * opens the test's transaction, so by this line the connection is in
     * exactly the state {@see ManualSchema} is written for. Only for classes
     * that actually use the trait: the rest have no migrated database to add a
     * table to, and touching the connection would make a unit test need one.
     *
     * See CLAUDE.md §4.6 for why those tables are not migrations.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $isSafe = $database === ':memory:'
            || str_ends_with($database, '_test')
            || str_ends_with($database, '_testing');

        if (! $isSafe) {
            throw new RuntimeException(
                "Refusing to run tests against database [{$database}]. ".
                'Point DB_DATABASE at a name ending in "_test" (see phpunit.xml).'
            );
        }

        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            ManualSchema::apply();
        }
    }
}
