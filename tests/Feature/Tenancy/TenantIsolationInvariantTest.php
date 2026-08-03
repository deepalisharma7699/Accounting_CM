<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The standing guard on multi-tenancy.
 *
 * PostgreSQL Row-Level Security would make isolation a property of the
 * database: a new table is protected the moment its policy is written, and
 * forgetting is loud. On MySQL isolation is a property of the *code*, so
 * forgetting one trait on one model is silent, and the leak only surfaces when
 * a customer sees another workshop's numbers.
 *
 * This test converts that silence into a failing build. Every model whose
 * table carries a tenant_id must use BelongsToTenant, or be listed below with
 * a reason.
 *
 * When Slice A adds parties, items, transactions and journal entries, this
 * test starts covering them automatically — no one has to remember to extend
 * it. That is the point.
 */
class TenantIsolationInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models that carry a tenant_id but deliberately do not use the trait.
     *
     * @var array<class-string<Model>, string>
     */
    private const EXEMPT = [
        User::class => 'Authentication must resolve a user before a tenant exists, so users are scoped explicitly in EloquentUserRepository and covered by TenantIsolationTest.',
    ];

    #[Test]
    public function every_tenant_owned_model_uses_the_belongs_to_tenant_trait(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->models() as $model) {
            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $checked++;

            if (array_key_exists($model, self::EXEMPT)) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
                $offenders[] = $model;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "These models have a tenant_id but do not use BelongsToTenant, so their queries are NOT isolated:\n  %s\n".
            'Add the trait, or add the model to TenantIsolationInvariantTest::EXEMPT with a reason.',
            implode("\n  ", $offenders)
        ));

        // Guards the guard: if the discovery below ever silently matches
        // nothing, this test would pass while checking zero models.
        $this->assertGreaterThan(0, $checked, 'No tenant-owned models were discovered — the scan is broken.');
    }

    #[Test]
    public function tenant_owned_tables_forbid_a_null_tenant(): void
    {
        $offenders = [];

        foreach ($this->models() as $model) {
            if (array_key_exists($model, self::EXEMPT)) {
                continue;
            }

            $table = (new $model)->getTable();

            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            // A nullable tenant_id on an owned table is a row belonging to
            // nobody — invisible to every tenant, and silently missing from
            // every report it should appear in.
            if ($this->isNullable($table, 'tenant_id')) {
                $offenders[] = $table;
            }
        }

        // Asserted rather than skipped when the list is empty: until Slice A
        // lands there are no non-exempt tenant-owned tables, and a test that
        // quietly asserts nothing is indistinguishable from one that broke.
        $this->assertSame([], $offenders, sprintf(
            'tenant_id is nullable on: %s. A tenant-owned row must always have an owner.',
            implode(', ', $offenders)
        ));
    }

    /**
     * @return array<int, class-string<Model>>
     */
    private function models(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = $this->classFor($file);

            if (class_exists($class)
                && is_subclass_of($class, Model::class)
                && ! (new \ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /**
     * @return class-string
     */
    private function classFor(SplFileInfo $file): string
    {
        $relative = Str::of($file->getRealPath())
            ->after(realpath(app_path()).DIRECTORY_SEPARATOR)
            ->replace(['/', '\\'], '\\')
            ->beforeLast('.php');

        return 'App\\'.$relative;
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        $this->fail("Column [{$column}] not found on [{$table}].");
    }
}
