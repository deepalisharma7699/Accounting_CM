<?php

namespace Tests\Feature\Personal;

use App\Models\JournalEntry;
use App\Models\PersonalExpense;
use App\Models\PersonalExpenseCategory;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Personal\PersonalCategoryDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The owner's personal diary — money out of their own pocket.
 *
 * Three things are established here. **Nothing posts**: an entry is not a
 * transaction and leaves the books exactly as they were. **Only the owner holds
 * it**: the grant is OWNER's alone out of the four default roles. And **one
 * diary per workshop**: two owners see one list, and another workshop sees none
 * of it.
 */
class PersonalExpenseTest extends TestCase
{
    use InteractsWithAuthModule;
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalogue();

        // The workshop's real OWNER role, from RoleDefaults — so the grant
        // under test is the one production gives, not one written here.
        $this->tenant = Tenant::factory()->create();
        $this->owner = $this->userWithDefaultRole($this->tenant, 'OWNER');
    }

    /* ---------------------------------------------------------------------
     | The diary
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_new_workshop_starts_with_the_default_categories(): void
    {
        $names = $this->api()->getJson('/api/v1/personal-expenses/meta')
            ->assertOk()
            ->json('data.categories.*.name');

        $this->assertSame(PersonalCategoryDefaults::names(), $names);
    }

    #[Test]
    public function an_entry_is_recorded_listed_and_totalled(): void
    {
        $groceries = $this->categoryId('Household & groceries');
        $fuel = $this->categoryId('Fuel & travel');

        $this->record(['category_id' => $groceries, 'amount' => '1250.50', 'notes' => 'Weekly vegetables'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '1250.50')
            ->assertJsonPath('data.category', 'Household & groceries')
            ->assertJsonPath('data.paid_by', 'upi')
            ->assertJsonPath('data.created_by', $this->owner->name);

        $this->record(['category_id' => $groceries, 'amount' => '249.50']);
        $this->record(['category_id' => $fuel, 'amount' => '2000']);

        $response = $this->api()->getJson('/api/v1/personal-expenses')->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.summary.count', 3)
            ->assertJsonPath('meta.summary.total', '3500.00')
            // Largest first, so the list's header reads as "where it went".
            ->assertJsonPath('meta.summary.by_category.0.name', 'Fuel & travel')
            ->assertJsonPath('meta.summary.by_category.1.total', '1500.00');
    }

    #[Test]
    public function the_list_filters_and_the_summary_follows_the_filter(): void
    {
        $groceries = $this->categoryId('Household & groceries');
        $medical = $this->categoryId('Medical');

        $this->record(['category_id' => $groceries, 'amount' => '100', 'date' => '2026-09-01']);
        $this->record(['category_id' => $medical, 'amount' => '700', 'date' => '2026-09-15', 'notes' => 'Dentist']);
        $this->record(['category_id' => $medical, 'amount' => '300', 'date' => '2026-10-02', 'paid_by' => 'cash']);

        $this->api()->getJson("/api/v1/personal-expenses?category_id={$medical}")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.total', '1000.00');

        $this->api()->getJson('/api/v1/personal-expenses?from=2026-09-01&to=2026-09-30')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.total', '800.00');

        $this->api()->getJson('/api/v1/personal-expenses?search=dentist')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '700.00');

        $this->api()->getJson('/api/v1/personal-expenses?paid_by=cash')
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function nothing_reaches_the_books(): void
    {
        $this->record(['amount' => '5000'])->assertCreated();

        // Not a transaction, not a journal line — the workshop's money never
        // moved, so the books must read exactly as they did before.
        $this->actingForTenant($this->tenant, function (): void {
            $this->assertSame(0, Transaction::count());
            $this->assertSame(0, JournalEntry::count());
        });
    }

    #[Test]
    public function an_entry_is_corrected_and_deleted(): void
    {
        $id = $this->record(['amount' => '400'])->json('data.id');
        $medical = $this->categoryId('Medical');

        $this->api()->patchJson("/api/v1/personal-expenses/{$id}", [
            'amount' => '450.25',
            'category_id' => $medical,
            'notes' => '',
        ])->assertOk()
            ->assertJsonPath('data.amount', '450.25')
            ->assertJsonPath('data.category', 'Medical')
            ->assertJsonPath('data.notes', null);

        $this->api()->getJson("/api/v1/personal-expenses/{$id}")
            ->assertOk()
            ->assertJsonPath('data.amount', '450.25');

        $this->api()->deleteJson("/api/v1/personal-expenses/{$id}")->assertOk();

        $this->api()->getJson("/api/v1/personal-expenses/{$id}")->assertNotFound();
    }

    #[Test]
    public function an_entry_without_an_amount_or_a_category_is_refused_on_the_field(): void
    {
        $this->api()->postJson('/api/v1/personal-expenses', [
            'date' => '2026-10-01',
            'paid_by' => 'cash',
            'amount' => '0',
        ])->assertStatus(422)
            ->assertJsonValidationErrorFor('amount', 'error.details.fields')
            ->assertJsonValidationErrorFor('category_id', 'error.details.fields');
    }

    /* ---------------------------------------------------------------------
     | Categories
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_category_is_added_renamed_archived_and_deleted(): void
    {
        $id = $this->api()->postJson('/api/v1/personal-expenses/categories', ['name' => 'Pet  care'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pet care')
            ->json('data.id');

        $this->api()->patchJson("/api/v1/personal-expenses/categories/{$id}", ['name' => 'Pets'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pets');

        $this->api()->patchJson("/api/v1/personal-expenses/categories/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->api()->deleteJson("/api/v1/personal-expenses/categories/{$id}")->assertOk();
    }

    #[Test]
    public function two_categories_cannot_share_a_name(): void
    {
        $this->api()->postJson('/api/v1/personal-expenses/categories', ['name' => 'Medical'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PERSONAL_CATEGORY_NAME_TAKEN');
    }

    #[Test]
    public function a_category_with_entries_is_archived_rather_than_deleted(): void
    {
        $medical = $this->categoryId('Medical');
        $entry = $this->record(['category_id' => $medical])->json('data.id');

        $this->api()->deleteJson("/api/v1/personal-expenses/categories/{$medical}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PERSONAL_CATEGORY_IN_USE');

        $this->api()->patchJson("/api/v1/personal-expenses/categories/{$medical}", ['is_active' => false])
            ->assertOk();

        // The entry already filed under it keeps it, and can still be edited…
        $this->api()->patchJson("/api/v1/personal-expenses/{$entry}", ['amount' => '99'])
            ->assertOk()
            ->assertJsonPath('data.category', 'Medical');

        // …but nothing new can be filed under it.
        $this->record(['category_id' => $medical])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERSONAL_CATEGORY_ARCHIVED');
    }

    /* ---------------------------------------------------------------------
     | Who may see it
     |-------------------------------------------------------------------- */

    #[Test]
    public function only_the_owner_role_is_given_the_diary(): void
    {
        foreach (['MANAGER', 'ACCOUNTANT', 'DATA_ENTRY'] as $slug) {
            $user = $this->userWithDefaultRole($this->tenant, $slug);

            $this->withHeaders($this->authHeader($user))
                ->getJson('/api/v1/personal-expenses')
                ->assertForbidden();

            $this->withHeaders($this->authHeader($user))
                ->postJson('/api/v1/personal-expenses', $this->entry())
                ->assertForbidden();
        }
    }

    #[Test]
    public function two_owners_of_one_workshop_share_one_diary(): void
    {
        $this->record(['amount' => '300'])->assertCreated();

        $partner = $this->userWithDefaultRole($this->tenant, 'OWNER');

        $this->withHeaders($this->authHeader($partner))
            ->getJson('/api/v1/personal-expenses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.created_by', $this->owner->name);
    }

    #[Test]
    public function another_workshop_sees_none_of_it(): void
    {
        $id = $this->record()->json('data.id');
        $category = $this->categoryId('Medical');

        $other = Tenant::factory()->create();
        $stranger = $this->userWithDefaultRole($other, 'OWNER');
        $as = fn () => $this->withHeaders($this->authHeader($stranger));

        $as()->getJson('/api/v1/personal-expenses')->assertOk()->assertJsonCount(0, 'data');
        $as()->getJson("/api/v1/personal-expenses/{$id}")->assertNotFound();
        $as()->patchJson("/api/v1/personal-expenses/{$id}", ['amount' => '1'])->assertNotFound();
        $as()->deleteJson("/api/v1/personal-expenses/{$id}")->assertNotFound();

        // Another workshop's category id is not adopted.
        $as()->postJson('/api/v1/personal-expenses', $this->entry(['category_id' => $category]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PERSONAL_CATEGORY_UNKNOWN');

        $this->actingForTenant($this->tenant, fn () => $this->assertSame(1, PersonalExpense::count()));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    private function api(): TestCase
    {
        return $this->withHeaders($this->authHeader($this->owner));
    }

    private function userWithDefaultRole(Tenant $tenant, string $slug): User
    {
        $role = Role::where('tenant_id', $tenant->id)->where('slug', $slug)->firstOrFail();

        return User::factory()->forTenant($tenant)->withRole($role)->create();
    }

    private function categoryId(string $name): int
    {
        return (int) $this->actingForTenant(
            $this->tenant,
            fn () => PersonalExpenseCategory::where('name', $name)->value('id'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-10-01',
            'category_id' => $this->categoryId('Other'),
            'amount' => '500',
            'paid_by' => 'upi',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(array $overrides = []): TestResponse
    {
        return $this->api()->postJson('/api/v1/personal-expenses', $this->entry($overrides));
    }
}
