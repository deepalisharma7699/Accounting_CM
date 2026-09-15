<?php

namespace Tests\Feature\Inventory;

use App\Enums\SystemAccount;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithStock;
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
    use InteractsWithAuthModule;
    use InteractsWithLedger;
    use InteractsWithStock;
    use InteractsWithTenancy;
    use RefreshDatabase;

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

    /**
     * The three fields the variant endpoint used to swallow.
     *
     * `barcode`, `min_stock` and `purchase_price` each had a column, a fillable
     * entry and service code that read and wrote them — and no rule in
     * {@see \App\Http\Requests\Item\StoreVariantRequest}, whose `payload()`
     * therefore dropped them on the way in. The universal create form could set
     * them once and nothing could ever change them again, which is not a
     * validation gap but a field the product did not have.
     */
    #[Test]
    public function a_variant_records_its_barcode_floor_and_buying_price(): void
    {
        $headers = $this->authHeader($this->owner);

        $item = $this->withHeaders($headers)
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $variant = $this->withHeaders($headers)
            ->postJson("/api/v1/items/{$item}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
                'barcode' => '8901234567890',
                'sell_price' => '18500.00',
                'purchase_price' => '14200.00',
                'reorder_level' => '4',
                'min_stock' => '2',
            ])
            ->assertCreated()
            ->assertJsonPath('data.barcode', '8901234567890')
            ->assertJsonPath('data.purchase_price', '14200.00')
            // Reorder level and floor are different questions — order more at 4,
            // never fall below 2 — and collapsing them loses the difference
            // between a purchase to plan and a purchase to make today.
            ->assertJsonPath('data.reorder_level', '4.000')
            ->assertJsonPath('data.min_stock', '2.000')
            ->json('data.id');

        // And every one of them can be corrected afterwards, which is the half
        // that did not exist.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$item}/variants/{$variant}", [
                'barcode' => '8901234567891',
                'purchase_price' => '13750.50',
                'min_stock' => '3',
            ])
            ->assertOk()
            ->assertJsonPath('data.barcode', '8901234567891')
            ->assertJsonPath('data.purchase_price', '13750.50')
            ->assertJsonPath('data.min_stock', '3.000')
            // Untouched keys are left alone: a PATCH that changes the floor does
            // not clear the price beside it.
            ->assertJsonPath('data.sell_price', '18500.00');

        // Null clears, which is a real edit rather than an omission.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$item}/variants/{$variant}", ['purchase_price' => null])
            ->assertOk()
            ->assertJsonPath('data.purchase_price', null);
    }

    /**
     * A barcode is not case-folded and a SKU is.
     *
     * A SKU is something a person types, so folding it is what makes "mot-5hp"
     * and "MOT-5HP" one code. A barcode is what a scanner emits, and folding it
     * would stop the stored value matching the label it was read from.
     */
    #[Test]
    public function a_barcode_keeps_its_case_where_a_sku_does_not(): void
    {
        $headers = $this->authHeader($this->owner);

        $item = $this->withHeaders($headers)
            ->postJson('/api/v1/items', $this->motorPayload())
            ->json('data.id');

        $this->withHeaders($headers)
            ->postJson("/api/v1/items/{$item}/variants", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
                'sku' => 'mot-5hp',
                'barcode' => 'ab12cd34',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'MOT-5HP')
            ->assertJsonPath('data.barcode', 'ab12cd34');
    }

    /**
     * The universal create form and the variant editor accept the same values.
     *
     * They are two requests over one record — the first writes the variant, the
     * second is the only way to correct it — so a bound one enforces and the
     * other does not is a value this application stored and then refused to
     * accept back. It showed up as a 422 on a field nobody had touched.
     */
    #[Test]
    public function an_attribute_written_by_the_create_form_can_be_saved_again_by_the_editor(): void
    {
        $headers = $this->authHeader($this->owner);

        // Longer than the 60 characters the variant editor used to allow, and
        // within the 120 the create form has always allowed.
        $frame = str_repeat('B', 90);

        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/items', $this->motorPayload([
                'with_variant' => true,
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440', 'frame' => $frame],
            ]))
            ->assertCreated();

        $item = $created->json('data.id');
        $variant = $created->json('data.variants.0.id');

        $this->assertSame($frame, $created->json('data.variants.0.attributes.frame'));

        // Correcting the price beside it must not be refused because of a value
        // this application itself wrote.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$item}/variants/{$variant}", [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440', 'frame' => $frame],
                'sell_price' => '19000.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.frame', $frame)
            ->assertJsonPath('data.sell_price', '19000.00');
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
     | Opening stock
     |
     | The universal form records what is already on the shelf, and it does so by
     | posting an ordinary stock adjustment through the ordinary engine. What
     | these check is the part a 201 does not prove: that a quantity actually
     | moved, that the Inventory account learned about it, and that the one case
     | where it could not be valued is refused rather than posted at nothing.
     |-------------------------------------------------------------------- */

    /**
     * A user who may both catalogue and post.
     *
     * Cataloguing is an ITEMS grant and recording a quantity is a TRANSACTIONS
     * one, and the class's own `$owner` deliberately holds only the first — see
     * the skip test at the end of this section, which is what that separation is
     * for.
     *
     * @return array{0: Tenant, 1: User}
     */
    private function stockkeeper(string $role): array
    {
        return $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'], ['WRITE', 'TRANSACTIONS'],
        ], $role);
    }

    /**
     * @return array<string, mixed>
     */
    private function motorWithOpeningStock(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Crompton 5 HP Motor',
            'category_id' => $this->categoryId('motor', $tenant),
            'gst_rate' => '18',
            'with_variant' => true,
            'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            'sku' => 'mot-5hp',
            'sell_price' => '18000.00',
            'opening_stock' => '4',
            'opening_cost' => '13500.00',
        ], $overrides);
    }

    private function onlyVariantOf(Tenant $tenant, int $itemId): ItemVariant
    {
        return $this->actingForTenant(
            $tenant,
            fn () => ItemVariant::query()->where('item_id', $itemId)->sole(),
        );
    }

    #[Test]
    public function opening_stock_reaches_the_shelf_and_the_inventory_account(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_POSTS');

        $created = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant))
            ->assertCreated();

        // Nothing was skipped, so nothing is warned about.
        $this->assertNull($created->json('meta.warnings'));

        $variant = $this->onlyVariantOf($tenant, (int) $created->json('data.id'));
        $position = $this->stockPositionOf($tenant, $variant);

        $this->assertSame('4.000', $position['quantity']);
        $this->assertSame('54000.00', $position['value']);
        $this->assertSame('13500.00', $position['average_cost']);

        // §8.2 — the stock impact, not merely that the request succeeded. The
        // shelf and the books are written from one figure in one transaction, so
        // these are the engine's guarantee holding rather than a reconciliation.
        $this->assertSame('54000.00', $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);

        // One document, and it says what it is on the day book.
        $document = $this->actingForTenant($tenant, fn () => Transaction::query()->sole());

        $this->assertSame('Opening stock for Crompton 5 HP Motor', $document->notes);
        $this->assertSame(now()->toDateString(), $document->date->toDateString());
    }

    /**
     * The buying price on the same form is the fallback, because it is the
     * number they just typed and the honest answer to "what is this worth".
     */
    #[Test]
    public function the_opening_cost_falls_back_to_the_buying_price(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_FALLBACK');

        $created = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant, [
                'opening_cost' => null,
                'purchase_price' => '12000.00',
            ]))
            ->assertCreated()
            // And it is stored on the variant as well, rather than only used
            // once and dropped — which is what it was before P0.
            ->assertJsonPath('data.variants.0.purchase_price', '12000.00');

        $position = $this->stockPositionOf($tenant, $this->onlyVariantOf($tenant, (int) $created->json('data.id')));

        $this->assertSame('48000.00', $position['value']);
        $this->assertSame('12000.00', $position['average_cost']);
    }

    /**
     * The count is dated when it was taken, not when it was typed.
     *
     * `opening_date` has been validated by the request since the universal form
     * was written and reached `postOpeningStock()`, and no screen had ever sent
     * one. A workshop enters its catalogue in the evenings of a week whose stock
     * it counted on the Sunday.
     */
    #[Test]
    public function the_count_can_be_dated_to_the_day_it_was_taken(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_DATED');

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant, [
                'opening_date' => now()->subDays(3)->toDateString(),
            ]))
            ->assertCreated();

        $document = $this->actingForTenant($tenant, fn () => Transaction::query()->sole());

        $this->assertSame(now()->subDays(3)->toDateString(), $document->date->toDateString());
    }

    /**
     * Stock that would arrive worth nothing is refused, and the product goes
     * with it.
     *
     * A brand-new variant has no movements, so the stock ledger's own fallback —
     * the last rate the workshop paid — is zero every time. What that produced
     * before the refusal was not a quiet zero: the posting template throws out a
     * voucher whose every line is worthless, so the create rolled back anyway
     * and the message named `adjustments`, a field the form does not have.
     */
    #[Test]
    public function opening_stock_with_nothing_to_value_it_at_is_refused(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_UNVALUED');

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant, [
                'opening_cost' => null,
                'purchase_price' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OPENING_STOCK_NEEDS_A_COST')
            // Named onto the box the person is looking at, which is the whole
            // point of raising it here rather than one layer down.
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['opening_cost']]]]);

        // The whole create is one transaction, so the product is not left behind
        // without the quantity somebody asked for.
        $this->actingForTenant($tenant, function () {
            $this->assertSame(0, Item::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    /**
     * A stated zero is refused too.
     *
     * `StockAdjustmentTemplate` is right that a workshop may carry a free sample
     * at nothing — but that is a stock-take somebody goes to the Stock screen to
     * record, having decided it. Reached through a catalogue form, a zero in a
     * cost box is a box somebody tabbed past, and the consequence is a shelf the
     * Inventory account never learns about and a first sale reporting the whole
     * price as profit.
     */
    #[Test]
    public function opening_stock_priced_at_zero_is_refused_as_well(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_ZERO');

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant, [
                'opening_cost' => '0',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OPENING_STOCK_NEEDS_A_COST');

        $this->actingForTenant($tenant, fn () => $this->assertSame(0, Item::query()->count()));
    }

    /**
     * The refusal is about the *value*, and it runs after the two skips.
     *
     * A category that holds no stock never reaches it: an hour of labour with an
     * opening quantity typed against it is saved, warned about and given no
     * quantity — refusing it for the missing cost would be refusing a product
     * over a field that could never have applied to it.
     */
    #[Test]
    public function an_opening_quantity_on_something_unstocked_is_warned_about_rather_than_refused(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_SERVICE');

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', [
                'name' => 'Rewinding Labour',
                'category_id' => $this->categoryId('service', $tenant),
                'gst_rate' => '18',
                'with_variant' => true,
                'sell_price' => '2500.00',
                'opening_stock' => '4',
            ])
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0.code', 'OPENING_STOCK_SKIPPED');

        $this->actingForTenant($tenant, function () {
            $this->assertSame(1, Item::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
        });
    }

    /**
     * A part bought to order is not labour, and the skip says so.
     *
     * `tracksStock()` is false for both — for the category that can never hold a
     * quantity, and for the product whose workshop chose not to hold one. Blaming
     * the category for the second sends somebody to the Category Master to fix a
     * switch that is on this form.
     */
    #[Test]
    public function a_product_the_workshop_chose_not_to_stock_is_told_which_switch_it_was(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_UNSTOCKED');

        $created = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant, ['is_stock' => false]))
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0.code', 'OPENING_STOCK_SKIPPED');

        $this->assertStringContainsString('keep stock of this', $created->json('meta.warnings.0.message'));

        $this->actingForTenant($tenant, fn () => $this->assertSame(0, StockMovement::query()->count()));
    }

    /**
     * Cataloguing is an ITEMS grant; recording a quantity is a TRANSACTIONS one.
     *
     * A clerk who may add a bearing but not write to the ledger gets the bearing
     * — refusing the whole request would mean they could not add it at all — and
     * is told plainly, with the count in it, what was not recorded.
     */
    #[Test]
    public function a_clerk_without_the_transactions_grant_keeps_the_product_and_loses_the_quantity(): void
    {
        [$tenant, $clerk] = $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'],
        ], 'CLERK_NO_POSTING');

        $response = $this->withHeaders($this->authHeader($clerk))
            ->postJson('/api/v1/items', $this->motorWithOpeningStock($tenant))
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0.code', 'OPENING_STOCK_SKIPPED');

        $this->assertStringContainsString(
            'The opening quantity was not recorded.',
            $response->json('meta.warnings.0.message'),
        );

        // The product and its variant survive; only the quantity did not.
        $this->actingForTenant($tenant, function () {
            $this->assertSame(1, Item::query()->count());
            $this->assertSame(1, ItemVariant::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
            $this->assertSame(0, Transaction::query()->count());
        });
    }

    /* ---------------------------------------------------------------------
     | Several variants on one create
     |
     | A motor family is bought in three ratings and catalogued in one sitting.
     | What these check is that the ratings arrive as one act — one product, one
     | stock adjustment — and that when one of them is wrong the refusal says
     | which, because five blocks on a form are indistinguishable in a banner.
     |-------------------------------------------------------------------- */

    /**
     * A motor family in however many ratings, in the longhand the form sends.
     *
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function motorFamily(Tenant $tenant, array $variants): array
    {
        return [
            'name' => 'Crompton Induction Motor',
            'category_id' => $this->categoryId('motor', $tenant),
            'gst_rate' => '18',
            'variants' => $variants,
        ];
    }

    /**
     * One rating: the three attributes a motor is described by, and a SKU.
     *
     * @return array<string, mixed>
     */
    private function rating(string $hp, array $overrides = []): array
    {
        return array_merge([
            'sku' => 'MOT-'.str_replace('.', '-', $hp),
            'attributes' => ['hp' => $hp, 'phase' => '3', 'rpm' => '1440'],
        ], $overrides);
    }

    private function variantBySku(Tenant $tenant, string $sku): ItemVariant
    {
        return $this->actingForTenant(
            $tenant,
            fn () => ItemVariant::query()->where('sku', $sku)->sole(),
        );
    }

    #[Test]
    public function several_variants_arrive_together_on_one_stock_adjustment(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_FAMILY');

        $created = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorFamily($tenant, [
                $this->rating('3', ['opening_stock' => '2', 'opening_cost' => '9000.00']),
                $this->rating('5', ['opening_stock' => '4', 'opening_cost' => '13500.00']),
                $this->rating('7.5', ['opening_stock' => '1', 'opening_cost' => '21000.00']),
            ]))
            ->assertCreated()
            ->assertJsonCount(3, 'data.variants');

        // Three distinct ratings and every quantity valued, so there is nothing
        // to warn about.
        $this->assertNull($created->json('meta.warnings'));

        foreach ([['MOT-3', '2.000', '18000.00'], ['MOT-5', '4.000', '54000.00'], ['MOT-7-5', '1.000', '21000.00']] as [$sku, $quantity, $value]) {
            $position = $this->stockPositionOf($tenant, $this->variantBySku($tenant, $sku));

            $this->assertSame($quantity, $position['quantity'], $sku.' is not on the shelf.');
            $this->assertSame($value, $position['value'], $sku.' is not worth what it cost.');
        }

        /*
        | One document, not three.
        |
        | Cataloguing a family is one act and reads on the day book as one, where
        | three vouchers stamped the same minute read as three separate
        | stock-takes. §8.2 — the stock impact, not merely that the request
        | succeeded.
        */
        $document = $this->actingForTenant($tenant, fn () => Transaction::query()->sole());

        $this->assertSame('Opening stock for Crompton Induction Motor', $document->notes);
        $this->actingForTenant($tenant, fn () => $this->assertSame(3, StockMovement::query()->count()));

        $this->assertSame('93000.00', $this->balanceOf($tenant, SystemAccount::Inventory));
        $this->assertStockAgreesWithInventoryAccount($tenant);
        $this->assertBooksBalance($tenant);
    }

    /**
     * A rating catalogued before any of it is on the shelf is still a rating.
     *
     * The form offers a quantity per block and most of them are left empty — a
     * workshop lists what it deals in, not only what it happens to hold today.
     */
    #[Test]
    public function a_variant_with_no_opening_quantity_is_left_off_the_document(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_PARTIAL');

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorFamily($tenant, [
                $this->rating('3', ['opening_stock' => '2', 'opening_cost' => '9000.00']),
                $this->rating('5'),
                $this->rating('7.5', ['opening_stock' => '1', 'opening_cost' => '21000.00']),
            ]))
            ->assertCreated()
            ->assertJsonCount(3, 'data.variants');

        $this->actingForTenant($tenant, function () {
            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame(2, StockMovement::query()->count());
        });

        $this->assertSame('0.000', $this->stockPositionOf($tenant, $this->variantBySku($tenant, 'MOT-5'))['quantity']);
        $this->assertSame('39000.00', $this->balanceOf($tenant, SystemAccount::Inventory));
    }

    /**
     * A refusal raised about one variant says which variant it was about.
     *
     * The SKU rule lives in ItemVariantService and legitimately does not know it
     * was called for the third block of a repeater. Without the scoping the form
     * paints "a variant with SKU MOT-5 already exists" on a banner above three
     * identical blocks and leaves somebody comparing them by eye.
     */
    #[Test]
    public function a_refusal_names_the_variant_it_is_about(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_DUPLICATE_SKU');

        $response = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorFamily($tenant, [
                $this->rating('3'),
                $this->rating('5'),
                $this->rating('7.5', ['sku' => 'MOT-3']),
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ITEM_SKU_TAKEN');

        $this->assertArrayHasKey('variants.2.sku', $response->json('error.details.fields'));

        // The whole create is one transaction: the two good ratings do not
        // survive without the third.
        $this->actingForTenant($tenant, function () {
            $this->assertSame(0, Item::query()->count());
            $this->assertSame(0, ItemVariant::query()->count());
        });
    }

    /**
     * And so does the opening-stock refusal, which is raised a step later.
     *
     * It runs after every variant exists — the two skips come first — so the
     * index it reports is the one the block was submitted under rather than the
     * order the costs happened to be resolved in.
     */
    #[Test]
    public function an_unvalued_opening_quantity_names_its_own_variant(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_UNVALUED_BLOCK');

        $response = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorFamily($tenant, [
                $this->rating('3', ['opening_stock' => '2', 'opening_cost' => '9000.00']),
                $this->rating('5', ['opening_stock' => '4']),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OPENING_STOCK_NEEDS_A_COST');

        $this->assertArrayHasKey('variants.1.opening_cost', $response->json('error.details.fields'));

        $this->actingForTenant($tenant, function () {
            $this->assertSame(0, Item::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
        });
    }

    /**
     * Two blocks describing the same thing are reported, never refused.
     *
     * M5's treatment of a shared GSTIN, and for the same reason: two 5 HP / 1440
     * rows are usually one motor entered twice, which splits one stock balance in
     * half — but a workshop stocking two brands at identical ratings legitimately
     * has two, and only the person who typed them knows which this is.
     */
    #[Test]
    public function variants_described_the_same_way_are_warned_about_rather_than_refused(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_DUPLICATE_SPEC');

        $created = $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', $this->motorFamily($tenant, [
                $this->rating('5', ['sku' => 'MOT-5-CG']),
                $this->rating('3'),
                $this->rating('5', ['sku' => 'MOT-5-ABB']),
            ]))
            ->assertCreated()
            ->assertJsonCount(3, 'data.variants')
            ->assertJsonPath('meta.warnings.0.code', 'ITEM_VARIANT_DUPLICATE');

        // Named by where they are on the form, which is the only thing telling
        // them apart — their specifications are identical by definition.
        $this->assertSame([1, 3], $created->json('meta.warnings.0.positions'));
        $this->assertStringContainsString('Variants 1 and 3', $created->json('meta.warnings.0.message'));
    }

    /**
     * Two variants that describe nothing are not duplicates of each other.
     *
     * Labour has no attribute bag at all — an hour of rewinding is an hour of
     * rewinding — so a rule that matched empty specification against empty
     * specification would warn about every second block on that category, every
     * time. What tells two of those apart is the SKU, and a repeated SKU is
     * refused outright.
     */
    #[Test]
    public function variants_with_no_specification_are_not_duplicates_of_one_another(): void
    {
        [$tenant, $keeper] = $this->stockkeeper('STOCKKEEPER_NO_SPEC');

        $this->withHeaders($this->authHeader($keeper))
            ->postJson('/api/v1/items', [
                'name' => 'Rewinding Labour',
                'category_id' => $this->categoryId('service', $tenant),
                'gst_rate' => '18',
                'variants' => [
                    ['sku' => 'LAB-STD', 'sell_price' => '2500.00'],
                    ['sku' => 'LAB-URGENT', 'sell_price' => '4000.00'],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonMissingPath('meta.warnings');
    }

    /**
     * The skip warning counts what it left behind.
     *
     * "Opening stock was skipped" on a form that offered a box per block leaves
     * somebody counting the boxes to work out what they have to go and ask for.
     */
    #[Test]
    public function the_skip_warning_counts_the_quantities_it_left_behind(): void
    {
        [$tenant, $clerk] = $this->tenantWithUser([
            ['READ', 'ITEMS'], ['WRITE', 'ITEMS'],
        ], 'CLERK_NO_POSTING_FAMILY');

        $response = $this->withHeaders($this->authHeader($clerk))
            ->postJson('/api/v1/items', $this->motorFamily($tenant, [
                $this->rating('3', ['opening_stock' => '2', 'opening_cost' => '9000.00']),
                $this->rating('5', ['opening_stock' => '4', 'opening_cost' => '13500.00']),
                $this->rating('7.5'),
            ]))
            ->assertCreated()
            ->assertJsonPath('meta.warnings.0.code', 'OPENING_STOCK_SKIPPED');

        $this->assertStringContainsString(
            'The opening quantities for 2 variants were not recorded.',
            $response->json('meta.warnings.0.message'),
        );

        // The product and all three ratings survive; only the quantities did not.
        $this->actingForTenant($tenant, function () {
            $this->assertSame(3, ItemVariant::query()->count());
            $this->assertSame(0, StockMovement::query()->count());
        });
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

    /**
     * A rating is archived, restored and signed off one at a time.
     *
     * All three are the same PATCH with a different flag, and none of them had a
     * caller until the drawer's variant rows grew the controls. What is asserted
     * here is the part the screen depends on and cannot check for itself: the two
     * flags are **independent**, so confirming a family leaves its ratings in the
     * queue and confirming a rating does not confirm the family above it.
     *
     * That independence is deliberate. A variant inherits `is_draft` from the
     * item it was invented under, and an importer that guessed a product also
     * guessed every rating below it — one sign-off for the lot would be one click
     * claiming somebody read them all.
     */
    #[Test]
    public function a_variant_is_archived_restored_and_signed_off_one_flag_at_a_time(): void
    {
        $headers = $this->authHeader($this->owner);

        $draft = $this->actingForTenant(
            $this->tenant,
            fn () => Item::factory()->part()->draft()->create()
        );

        $variant = $this->withHeaders($headers)
            ->postJson("/api/v1/items/{$draft->id}/variants", [
                'attributes' => ['size' => '6205'],
                'sell_price' => '250.00',
            ])
            ->assertCreated()
            // Inherited from the family it was invented under, which is what puts
            // it in the queue at all.
            ->assertJsonPath('data.is_draft', true)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        // Off the shelf, and everything recorded against it stays where it is.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$draft->id}/variants/{$variant}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.sell_price', '250.00');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$draft->id}/variants/{$variant}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        // Signing off the family leaves the rating under it waiting.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$draft->id}", ['is_draft' => false])
            ->assertOk()
            ->assertJsonPath('data.is_draft', false);

        $this->withHeaders($headers)
            ->getJson('/api/v1/items/meta')
            ->assertOk()
            ->assertJsonPath('data.draft_counts.items', 0)
            // The count the review banner had never read. Without it a workshop
            // whose import left ratings unchecked is told there is nothing to do.
            ->assertJsonPath('data.draft_counts.variants', 1);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/items/{$draft->id}/variants/{$variant}", ['is_draft' => false])
            ->assertOk()
            ->assertJsonPath('data.is_draft', false)
            // The flag alone: signing off is not an edit, and the price beside it
            // is somebody else's answer.
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.sell_price', '250.00');

        $this->withHeaders($headers)
            ->getJson('/api/v1/items/meta')
            ->assertOk()
            ->assertJsonPath('data.draft_counts.variants', 0);
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
