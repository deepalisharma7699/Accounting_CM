<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The HTTP surface of the catalogue.
 *
 * The thing worth watching, beyond the usual permissions and isolation, is that
 * **no endpoint here reports a quantity or a cost**. Those are M8's, and a
 * placeholder now would invite a client to render a zero as "none in stock" when it
 * means "nobody asked".
 */
class ItemApiTest extends TestCase
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
            'code' => 'mot-3ph',
            'hsn_sac' => '8501',
            'gst_rate' => '18',
        ], $overrides);
    }

    /* ---------------------------------------------------------------------
     | Creating
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_owner_can_add_an_item(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', '3-Phase Induction Motor')
            ->assertJsonPath('data.category_id', $this->categoryId('motor'))
            ->assertJsonPath('data.category_label', 'Motor')
            // Upper-cased on the way in, so a code always looks the same.
            ->assertJsonPath('data.code', 'MOT-3PH')
            // A decimal string, never a JSON number: this one gets multiplied by
            // an amount to compute tax.
            ->assertJsonPath('data.gst_rate', '18.00')
            // Defaulted from the category, so the ordinary case needed no decision.
            ->assertJsonPath('data.base_uom', 'piece')
            ->assertJsonPath('data.base_uom_symbol', 'pc')
            ->assertJsonPath('data.tracks_stock', true)
            // Goods carry an HSN code; the label says which word to use.
            ->assertJsonPath('data.tax_code_label', 'HSN');
    }

    #[Test]
    public function a_service_item_reports_a_sac_code_and_cannot_hold_stock(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', [
                'name' => 'Rewinding Labour',
                'category_id' => $this->categoryId('service'),
                'hsn_sac' => '998719',
                'gst_rate' => '18',
                // Asked for explicitly, and overruled: an hour is produced at the
                // moment it is sold.
                'is_stock' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_stock', false)
            ->assertJsonPath('data.tracks_stock', false)
            ->assertJsonPath('data.can_hold_stock', false)
            ->assertJsonPath('data.base_uom', 'hour')
            ->assertJsonPath('data.tax_code_label', 'SAC');
    }

    #[Test]
    public function no_endpoint_reports_a_quantity_or_a_cost(): void
    {
        $created = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$created->json('data.id')}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
                'sell_price' => '18500.00',
            ])
            ->assertCreated();

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/items/{$created->json('data.id')}")
            ->assertOk();

        // M8's answer, deliberately absent until M8: a zero here would read as
        // "none in stock" when it means "nobody asked".
        foreach (['qty_on_hand', 'avg_cost', 'stock_value', 'quantity'] as $absent) {
            $this->assertArrayNotHasKey($absent, $response->json('data'));
            $this->assertArrayNotHasKey($absent, $response->json('data.variants.0'));
        }
    }

    #[Test]
    public function a_duplicate_name_is_refused_with_the_reason(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['code' => 'MOT-2']))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_NAME_TAKEN')
            ->assertJsonPath('error.details.field', 'name');
    }

    #[Test]
    public function a_bad_hsn_code_or_rate_is_refused_by_validation(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['hsn_sac' => '85']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 0.18 is the classic mistake: a fraction where a percentage belongs.
        // Accepted as a number, so it has to be caught by range rather than shape.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['name' => 'Other', 'gst_rate' => '180']))
            ->assertStatus(422);
    }

    /* ---------------------------------------------------------------------
     | Variants
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_variant_is_validated_against_its_items_type(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        // The rating is what makes a motor identifiable; without it nobody can
        // tell one row from another.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", [
                'attributes' => ['phase' => '3', 'rpm' => '1440'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ITEM_ATTRIBUTES_MISSING')
            ->assertJsonPath('error.details.missing.0', 'hp');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", [
                'sku' => 'mot-5hp',
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
                'sell_price' => '18500.00',
                'markup_percent' => '20',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'MOT-5HP')
            ->assertJsonPath('data.display_label', '5 HP / 3 ph / 1440 RPM')
            // What the workshop typed, which was nothing — distinct from what to
            // show, so an edit form does not overwrite one with the other.
            ->assertJsonPath('data.label', null)
            ->assertJsonPath('data.sell_price', '18500.00')
            ->assertJsonPath('data.attributes.hp', '5');
    }

    #[Test]
    public function a_duplicate_specification_is_saved_and_reported_as_a_warning(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $spec = ['attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440']];

        $first = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", $spec + ['label' => 'Crompton 5 HP'])
            ->assertCreated();

        // The save succeeds — two brands at one rating is a real arrangement — and
        // the duplicate is put in front of the user while they can still merge them.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", $spec + ['label' => 'Kirloskar 5 HP'])
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0.code', 'ITEM_VARIANT_DUPLICATE')
            ->assertJsonPath('meta.warnings.0.variant_ids.0', $first->json('data.id'));
    }

    #[Test]
    public function a_variant_can_be_edited_and_archived(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $variant = $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}/variants/{$variant}", [
                'sell_price' => '19750.50',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.sell_price', '19750.50')
            ->assertJsonPath('data.is_active', false)
            // The attributes were not mentioned, so they are untouched.
            ->assertJsonPath('data.attributes.rpm', '1440');
    }

    /**
     * The nesting in the URL has to mean something. Without the check, editing
     * variant 12 of item 3 through `/items/7/variants/12` would succeed inside the
     * right workshop — so the tenant scope would not catch it — and the caller
     * would be told the edit applied to the item they were looking at.
     */
    #[Test]
    public function a_variant_cannot_be_edited_through_another_items_url(): void
    {
        $headers = $this->authHeader($this->owner);

        $motor = $this->withHeaders($headers)
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $bearing = $this->withHeaders($headers)
            ->postJson('/api/v1/items', ['name' => 'Ball Bearing', 'category_id' => $this->categoryId('part')])
            ->json('data.id');

        $variant = $this->withHeaders($headers)
            ->postJson("/api/v1/items/{$motor}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->json('data.id');

        // A 404, not a 403: from here there is no variant of the bearing with
        // that id.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$bearing}/variants/{$variant}", ['sell_price' => '1.00'])
            ->assertNotFound();

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/items/{$bearing}/variants/{$variant}")
            ->assertNotFound();

        // And the real one is untouched.
        $this->withHeaders($headers)
            ->getJson("/api/v1/items/{$motor}")
            ->assertOk()
            ->assertJsonPath('data.variants.0.sell_price', null);
    }

    #[Test]
    public function a_variant_of_another_workshops_item_cannot_be_reached(): void
    {
        $other = Tenant::factory()->create();

        $theirs = $this->actingForTenant($other, fn () => Item::factory()->motor()->create());

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/items/{$theirs->id}/variants")
            ->assertNotFound();

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$theirs->id}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Listing
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_list_can_be_filtered_by_type_stock_and_draft(): void
    {
        $this->actingForTenant($this->tenant, function () {
            Item::factory()->motor()->create();
            Item::factory()->service()->create();
            Item::factory()->part()->draft()->create();
        });

        $headers = $this->authHeader($this->owner);

        $this->withHeaders($headers)->getJson('/api/v1/items')
            ->assertOk()->assertJsonCount(3, 'data');

        $motor = $this->categoryId('motor');

        $this->withHeaders($headers)->getJson("/api/v1/items?category_id={$motor}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category_id', $motor);

        // The review queue.
        $this->withHeaders($headers)->getJson('/api/v1/items?is_draft=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_draft', true);

        // Labour cannot be stocked, so it is the one this excludes.
        $this->withHeaders($headers)->getJson('/api/v1/items?is_stock=1')
            ->assertOk()->assertJsonCount(2, 'data');

        /*
        | And the complement, which is what the bill's item picker asks for: the
        | half of the catalogue /stock cannot answer for. Between them the two
        | queries cover the catalogue exactly once, which is what stops a family
        | being offered twice — or, worse, being offered as a line that names no
        | variant.
        */
        $this->withHeaders($headers)->getJson('/api/v1/items?is_stock=0')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_stock', false);
    }

    /**
     * A filter that does not exist is **ignored**, not refused.
     *
     * Pinned because that is a trap, and it has already been sprung once. The
     * item picker went on asking for `?type=service` after the ItemType enum was
     * deleted and the catalogue's vocabulary became data. Nothing failed: the
     * parameter was dropped, every item came back, and each stocked family was
     * then offered on a bill as a line naming no variant — which the posting
     * engine refuses, after the whole bill has been typed.
     *
     * There is no fix to make here. Laravel validates what it is given and
     * ignores the rest, and refusing unknown parameters would break every client
     * that appends a cache-buster. The fix is that a caller must filter on
     * something in {@see \App\Http\Requests\Item\IndexItemRequest::rules()}, and
     * this test is the reminder of what happens when one does not.
     */
    #[Test]
    public function an_unknown_filter_is_ignored_rather_than_narrowing_the_list(): void
    {
        $this->actingForTenant($this->tenant, function () {
            Item::factory()->motor()->create();
            Item::factory()->service()->create();
        });

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?type=service')
            ->assertOk()
            // Both, not the one the parameter appears to ask for.
            ->assertJsonCount(2, 'data');
    }

    /**
     * A fitter searching for "1440" is looking for a motor by its speed, which
     * lives on the variant. Without this the catalogue is only searchable by family
     * name, which is the one thing nobody remembers.
     */
    #[Test]
    public function search_reaches_variant_labels_and_skus(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", [
                'sku' => 'MOT-5HP-1440',
                'label' => '5 HP 1440 RPM',
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?search=1440')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $item);
    }

    #[Test]
    public function variants_are_opt_in_on_the_list(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->assertCreated();

        // A picker asking for family names has no use for the variants and does
        // not pay for the query — but the count is always there.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonMissingPath('data.0.variants')
            ->assertJsonPath('data.0.variant_count', 1);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?with_variants=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.variants')
            ->assertJsonPath('data.0.variants.0.display_label', '5 HP / 3 ph / 1440 RPM');
    }

    /* ---------------------------------------------------------------------
     | Meta
     |-------------------------------------------------------------------- */

    /**
     * An attribute schema copied into JavaScript is a copy that drifts, and the
     * drift shows up as a motor saved without its HP.
     */
    #[Test]
    public function the_meta_endpoint_publishes_the_attribute_schema_per_category(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items/meta')
            ->assertOk();

        // Keyed by `code` for the assertion's sake only. The payload identifies a
        // category by id — the code is a convenience the four seeded rows happen
        // to carry, and one an admin's own categories need not have at all.
        $categories = collect($response->json('data.categories'))->keyBy('code');

        $this->assertSame(
            ['motor', 'part', 'bulk_material', 'service'],
            $categories->keys()->all(),
        );

        $motor = $categories['motor'];
        $this->assertTrue($motor['can_hold_stock']);
        $this->assertSame('piece', $motor['default_uom']);
        $this->assertSame('HSN', $motor['tax_code_label']);
        $this->assertTrue($motor['attributes']['hp']['required']);
        $this->assertFalse($motor['attributes']['frame']['required']);
        $this->assertSame(['1', '3'], $motor['attributes']['phase']['values']);

        $service = $categories['service'];
        $this->assertFalse($service['can_hold_stock']);
        $this->assertSame('SAC', $service['tax_code_label']);
        $this->assertSame([], $service['attributes']);

        $units = collect($response->json('data.units'))->keyBy('value');
        $this->assertTrue($units['kg']['is_fractional']);
        $this->assertFalse($units['piece']['is_fractional']);
        $this->assertSame('pc', $units['piece']['symbol']);

        // The review-queue badge comes along, because every screen showing the
        // catalogue wants it and a second round trip for one integer is waste.
        $this->assertSame(0, $response->json('data.draft_counts.items'));
    }

    /* ---------------------------------------------------------------------
     | Editing, archiving, deleting
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_category_and_unit_are_not_editable_over_the_wire(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", [
                'category_id' => $this->categoryId('service'),
                'base_uom' => 'kg',
                'name' => 'Renamed Motor',
            ])
            ->assertOk()
            // Reclassifying would silently reinterpret a specification keyed by
            // the old category's fields. If the category was wrong, the product
            // was the wrong product.
            ->assertJsonPath('data.category_id', $this->categoryId('motor'))
            ->assertJsonPath('data.base_uom', 'piece')
            ->assertJsonPath('data.name', 'Renamed Motor');
    }

    #[Test]
    public function a_draft_item_is_confirmed_by_clearing_the_flag(): void
    {
        $draft = $this->actingForTenant($this->tenant, fn () => Item::factory()->part()->draft()->create());

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$draft->id}", ['is_draft' => false])
            ->assertOk()
            ->assertJsonPath('data.is_draft', false);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items/meta')
            ->assertOk()
            ->assertJsonPath('data.draft_counts.items', 0);
    }

    #[Test]
    public function an_item_with_variants_is_refused_deletion_and_told_to_archive(): void
    {
        $item = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/items/{$item}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/items/{$item}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_IN_USE')
            ->assertJsonPath('error.details.archive_instead', true);

        // Archiving is always available and leaves everything intact.
        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/items/{$item}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.variant_count', 1);
    }

    /* ---------------------------------------------------------------------
     | Permissions and tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function reading_the_catalogue_needs_the_items_grant(): void
    {
        [, $stranger] = $this->tenantWithUser([['READ', 'PARTIES']]);

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/items')
            ->assertForbidden();
    }

    /**
     * A part nobody has recorded yet turns up as often as a new customer, so a
     * clerk can add one — but not edit or delete an existing record.
     */
    #[Test]
    public function a_data_entry_user_can_add_an_item_but_not_edit_or_delete_one(): void
    {
        [$tenant, $clerk] = $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'],
        ], 'DATA_ENTRY_LIKE');

        $created = $this->withHeaders($this->authHeader($clerk))
            ->postJson('/api/v1/items', [
                'name' => 'Counter Bearing',
                // The clerk's *own* workshop's category. Every workshop is
                // provisioned with its own four, and posting another's id is
                // exactly the cross-tenant reach the next test checks is refused.
                'category_id' => $this->categoryId('part', $tenant),
            ])
            ->assertCreated();

        $id = $created->json('data.id');

        $this->withHeaders($this->authHeader($clerk))
            ->patchJson("/api/v1/items/{$id}", ['name' => 'Renamed'])
            ->assertForbidden();

        $this->withHeaders($this->authHeader($clerk))
            ->deleteJson("/api/v1/items/{$id}")
            ->assertForbidden();

        $this->assertSame('Counter Bearing', $this->actingForTenant($tenant, fn () => Item::find($id)->name));
    }

    #[Test]
    public function the_catalogue_is_invisible_to_another_workshop(): void
    {
        $mine = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        [, $stranger] = $this->tenantWithUser([['READ', 'ITEMS'], ['UPDATE', 'ITEMS'], ['DELETE', 'ITEMS']]);

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        foreach (['getJson', 'deleteJson'] as $method) {
            $this->withHeaders($this->authHeader($stranger))
                ->{$method}("/api/v1/items/{$mine}")
                ->assertNotFound();
        }

        $this->withHeaders($this->authHeader($stranger))
            ->patchJson("/api/v1/items/{$mine}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | The rate nobody typed
     |
     | The create form used to send '0' for an empty box, which is a value and
     | not an absence — so the category's own rate never got a chance and every
     | product saved at 0% GST whatever its category charged. Nothing on the
     | screen said so; the first sign was a purchase line taxed at nothing.
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_missing_gst_rate_falls_back_to_the_category(): void
    {
        $this->actingForTenant($this->tenant, fn () => ItemCategory::whereKey($this->categoryId('motor'))
            ->update(['default_gst_rate' => '18.00']));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['gst_rate' => null]))
            ->assertCreated()
            ->assertJsonPath('data.gst_rate', '18.00');
    }

    #[Test]
    public function an_omitted_gst_rate_falls_back_to_the_category(): void
    {
        $this->actingForTenant($this->tenant, fn () => ItemCategory::whereKey($this->categoryId('motor'))
            ->update(['default_gst_rate' => '12.00']));

        $payload = $this->motorPayload();
        unset($payload['gst_rate']);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $payload)
            ->assertCreated()
            ->assertJsonPath('data.gst_rate', '12.00');
    }

    #[Test]
    public function a_stated_rate_still_beats_the_category(): void
    {
        $this->actingForTenant($this->tenant, fn () => ItemCategory::whereKey($this->categoryId('motor'))
            ->update(['default_gst_rate' => '18.00']));

        // Copied onto the product, never referenced — correcting the category
        // next March must not restate what this already charges.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['gst_rate' => '5']))
            ->assertCreated()
            ->assertJsonPath('data.gst_rate', '5.00');
    }

    #[Test]
    public function a_rate_of_zero_is_still_a_rate_somebody_chose(): void
    {
        $this->actingForTenant($this->tenant, fn () => ItemCategory::whereKey($this->categoryId('motor'))
            ->update(['default_gst_rate' => '18.00']));

        // Exempt goods are real. An explicit 0 has to survive the fallback, or
        // there would be no way to say it at all.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/items', $this->motorPayload(['gst_rate' => '0']))
            ->assertCreated()
            ->assertJsonPath('data.gst_rate', '0.00');
    }

    /* ---------------------------------------------------------------------
     | A family with nothing under it
     |
     | It has no variant, so it has no stock row and a picker searching stock
     | cannot see it; it is stocked, so `is_stock=0` excludes it too. Between
     | them it was invisible, and "Nothing matched" is indistinguishable from a
     | product nobody ever entered — which is how a duplicate gets created.
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_can_list_only_the_families_that_have_nothing_under_them(): void
    {
        $this->actingForTenant($this->tenant, function () {
            Item::factory()->motor()->create(['name' => 'Motor 3']);

            ItemVariant::factory()
                ->for(Item::factory()->motor()->create(['name' => 'Motor 4']))
                ->motor()
                ->create();
        });

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?has_variants=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Motor 3');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?has_variants=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Motor 4');
    }

    #[Test]
    public function a_family_whose_only_variant_was_archived_counts_as_having_none(): void
    {
        // Nothing can be billed against it, so for this question it is bare.
        $this->actingForTenant($this->tenant, fn () => ItemVariant::factory()
            ->for(Item::factory()->motor()->create(['name' => 'Retired Motor']))
            ->motor()
            ->create(['is_active' => false]));

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items?has_variants=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Retired Motor');
    }

    #[Test]
    public function the_filter_is_absent_by_default(): void
    {
        $this->actingForTenant($this->tenant, function () {
            Item::factory()->motor()->create(['name' => 'Bare']);

            ItemVariant::factory()
                ->for(Item::factory()->motor()->create(['name' => 'Specified']))
                ->motor()
                ->create();
        });

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function an_anonymous_visitor_reaches_nothing(): void
    {
        $this->actingForTenant($this->tenant, fn () => ItemVariant::factory()
            ->for(Item::factory()->motor())
            ->motor()
            ->create());

        $this->getJson('/api/v1/items')->assertUnauthorized();
        $this->getJson('/api/v1/items/meta')->assertUnauthorized();
    }
}
