<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard against wiping a real database.
     *
     * The suite runs against MySQL (see phpunit.xml) and RefreshDatabase issues
     * `migrate:fresh`, which drops every table. If a stray environment variable
     * ever pointed the tests at the development database, that would destroy it
     * silently — so refuse to run unless the target is clearly a test database.
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
    }
}
