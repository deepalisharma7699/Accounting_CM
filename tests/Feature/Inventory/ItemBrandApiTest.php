<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemBrand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Brand Master, and the two things the catalogue listing owes it.
 *
 * The rule under almost every test here is the one the whole catalogue's
 * vocabulary follows: **a definition something already depends on may be
 * archived, but not removed.** A brand deleted out from under twelve products
 * would make twelve unbranded things, silently, with nothing anywhere to say it
 * happened.
 */
class ItemBrandApiTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'], ['UPDATE', 'ITEMS'], ['DELETE', 'ITEMS'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function motorPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '3-Phase Induction Motor',
            'category_id' => $this->categoryId('motor'),
            'hsn_sac' => '8501',
            'gst_rate' => '18',
        ], $overrides);
    }

    private function createBrand(string $name = 'Crompton'): int
    {
        return (int) $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/item-brands', ['name' => $name])
            ->assertCreated()
            ->json('data.id');
    }

    /* ---------------------------------------------------------------------
     | The master
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_brand_can_be_created_and_is_offered_to_the_create_form(): void
    {
        $id = $this->createBrand('Crompton');

        // The acceptance criterion for the dropdown: a brand added from the
        // drawer is on the form's vocabulary immediately, with no deployment and
        // no reload.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items/meta')
            ->assertOk()
            ->assertJsonPath('data.brands.0.id', $id)
            ->assertJsonPath('data.brands.0.label', 'Crompton')
            // A string, because that is what a <select> value always is.
            ->assertJsonPath('data.brands.0.value', (string) $id);
    }

    #[Test]
    public function two_brands_of_one_name_are_refused(): void
    {
        $this->createBrand('Crompton');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/item-brands', ['name' => 'Crompton'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BRAND_NAME_TAKEN');
    }

    #[Test]
    public function a_brand_can_be_renamed_and_every_product_carrying_it_follows(): void
    {
        $id = $this->createBrand('Crompton');

        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $id]))
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/item-brands/{$id}", ['name' => 'Crompton Greaves'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Crompton Greaves');

        // The point of the master: the name is read through the relation rather
        // than copied onto the product, so one edit renames it everywhere.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/items/{$item}")
            ->assertOk()
            ->assertJsonPath('data.brand', 'Crompton Greaves');
    }

    #[Test]
    public function a_brand_no_product_carries_can_be_deleted(): void
    {
        $id = $this->createBrand('Havells');

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/item-brands/{$id}")
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/item-brands')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_brand_products_carry_is_refused_deletion_and_told_to_archive(): void
    {
        $id = $this->createBrand('SKF');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $id]))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/item-brands/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BRAND_IN_USE');

        // The remedy the refusal names, and it works: archiving takes the brand
        // off the create form and leaves it naming what already carries it.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/item-brands/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items/meta')
            ->assertOk()
            ->assertJsonCount(0, 'data.brands');

        // Still on the master, so it can be restored — a list that hid what it
        // had just archived would look like a delete.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/item-brands')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_count', 1);
    }

    /* ---------------------------------------------------------------------
     | On a product
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_product_records_the_brand_it_was_created_with(): void
    {
        $id = $this->createBrand('Crompton');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $id]))
            ->assertCreated()
            ->assertJsonPath('data.brand_id', $id)
            ->assertJsonPath('data.brand', 'Crompton');
    }

    #[Test]
    public function a_product_may_have_no_brand_at_all(): void
    {
        // An unbranded bush is a real thing, and forcing a guess would put a
        // wrong make on the record.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->assertCreated()
            ->assertJsonPath('data.brand_id', null)
            ->assertJsonPath('data.brand', null);
    }

    #[Test]
    public function a_brand_can_be_changed_and_cleared_on_an_edit(): void
    {
        $crompton = $this->createBrand('Crompton');
        $havells = $this->createBrand('Havells');

        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $crompton]))
            ->json('data.id');

        // Editable, unlike the category: nothing downstream is keyed by a brand,
        // so correcting one is a correction rather than a reinterpretation.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", ['brand_id' => $havells])
            ->assertOk()
            ->assertJsonPath('data.brand_id', $havells)
            ->assertJsonPath('data.brand', 'Havells');

        // Sent as null clears it — a real edit, distinct from not mentioning it.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", ['brand_id' => null])
            ->assertOk()
            ->assertJsonPath('data.brand_id', null);

        // Not mentioning it leaves it alone.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", ['brand_id' => $crompton])
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", ['description' => 'Rewound in house'])
            ->assertOk()
            ->assertJsonPath('data.brand_id', $crompton);
    }

    #[Test]
    public function a_brand_that_does_not_exist_is_refused_rather_than_dropped(): void
    {
        // Saving the product with no brand would leave somebody looking at a form
        // that said "Crompton" and a record that says nothing.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => 987654]))
            ->assertNotFound();
    }

    #[Test]
    public function a_product_keeps_a_brand_that_has_since_been_archived(): void
    {
        $id = $this->createBrand('Crompton');

        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $id]))
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/item-brands/{$id}", ['is_active' => false])
            ->assertOk();

        // Off the picker, still the answer here — and still saveable, or the
        // product would be uneditable until somebody restored a brand they
        // deliberately retired.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", ['brand_id' => $id])
            ->assertOk()
            ->assertJsonPath('data.brand', 'Crompton');
    }

    #[Test]
    public function the_catalogue_is_searchable_by_brand(): void
    {
        $id = $this->createBrand('Crompton');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $id]))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['name' => 'Ball Bearing 6205']))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?search=Crompton')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'Crompton');
    }

    /* ---------------------------------------------------------------------
     | What the listing shows
     |-------------------------------------------------------------------- */

    /**
     * The Category column, which the listing draws straight from this key.
     *
     * It went blank once before, when `type_label` became `category_label` and a
     * reader was left behind — so the name is asserted on the listing payload
     * rather than only on the single-record one.
     */
    #[Test]
    public function every_listed_item_carries_its_category_name(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonPath('data.0.category_id', $this->categoryId('motor'))
            ->assertJsonPath('data.0.category_label', 'Motor');
    }

    /**
     * The Variants column. Counted with the page rather than stored, which is
     * what makes it right the moment a variant is added or removed.
     */
    #[Test]
    public function the_variant_count_follows_what_is_actually_under_an_item(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        // A family nobody has hung a variant off yet has none — zero, not null:
        // it cannot be sold, priced or counted, and the column should say so.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonPath('data.0.variant_count', 0);

        $variants = [];

        foreach ([['5', '1440'], ['7.5', '2880']] as [$hp, $rpm]) {
            $variants[] = $this->withHeaders($this->authHeader($this->owner))
                ->postJson("/api/v1/items/{$item}/variants", [
                    'attributes' => ['hp' => $hp, 'phase' => '3', 'rpm' => $rpm],
                ])
                ->assertCreated()
                ->json('data.id');
        }

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonPath('data.0.variant_count', 2);

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/items/{$item}/variants/{$variants[0]}")
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonPath('data.0.variant_count', 1);
    }

    /**
     * The stock screen searches the same way the catalogue does, through its own
     * query — so it has its own way of reaching the brand and its own way of
     * being left behind when the brand stops being a column.
     */
    #[Test]
    public function the_stock_position_list_is_searchable_by_brand(): void
    {
        [$tenant, $user] = $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'], ['UPDATE', 'ITEMS'], ['READ', 'STOCK'],
        ]);

        $brand = (int) $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/item-brands', ['name' => 'Crompton'])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/v1/items', [
                'name' => '3-Phase Induction Motor',
                'category_id' => $this->categoryId('motor', $tenant),
                'brand_id' => $brand,
                'with_variant' => true,
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->assertCreated();

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/stock?search=Crompton')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/v1/stock?search=Havells')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------------------------------------------------------------------
     | Permissions and isolation
     |-------------------------------------------------------------------- */

    #[Test]
    public function writing_the_brand_master_needs_more_than_writing_a_product(): void
    {
        // A clerk should be able to add a bearing without fetching the owner, and
        // should not be able to restructure the shop's vocabulary — the same
        // split the Category Master draws.
        [, $clerk] = $this->tenantWithUser([['READ', 'ITEMS'], ['WRITE', 'ITEMS']]);

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/item-brands')
            ->assertOk();

        $this->withHeaders($this->authHeader($clerk))
            ->postJson('/api/v1/item-brands', ['name' => 'Crompton'])
            ->assertForbidden();
    }

    #[Test]
    public function another_workshops_brands_are_invisible(): void
    {
        $mine = $this->createBrand('Crompton');

        [$theirs, $stranger] = $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'], ['UPDATE', 'ITEMS'], ['DELETE', 'ITEMS'],
        ]);

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/item-brands')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        foreach (['getJson', 'deleteJson'] as $method) {
            $this->withHeaders($this->authHeader($stranger))
                ->{$method}("/api/v1/item-brands/{$mine}")
                ->assertNotFound();
        }

        $this->withHeaders($this->authHeader($stranger))
            ->patchJson("/api/v1/item-brands/{$mine}", ['name' => 'Hijacked'])
            ->assertNotFound();

        // And a product cannot be filed under a brand belonging to somebody else.
        $this->withHeaders($this->authHeader($stranger))
            ->postJson('/api/v1/items', [
                'name' => 'Stranger Motor',
                'category_id' => $this->categoryId('motor', $theirs),
                'brand_id' => $mine,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function an_anonymous_visitor_reaches_nothing(): void
    {
        $this->actingForTenant($this->tenant, fn () => ItemBrand::create(['name' => 'Crompton']));

        $this->getJson('/api/v1/item-brands')->assertUnauthorized();
        $this->postJson('/api/v1/item-brands', ['name' => 'X'])->assertUnauthorized();
    }

    #[Test]
    public function a_brand_row_reports_how_many_products_carry_it(): void
    {
        $id = $this->createBrand('Crompton');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['brand_id' => $id]))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload([
                'name' => 'Single-Phase Motor',
                'brand_id' => $id,
            ]))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/item-brands')
            ->assertOk()
            ->assertJsonPath('data.0.item_count', 2);

        $this->assertSame(
            2,
            $this->actingForTenant($this->tenant, fn () => Item::where('brand_id', $id)->count()),
        );
    }
}
