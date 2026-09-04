<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\Accounting\InvalidItemAttributesException;
use App\Exceptions\Accounting\ItemInUseException;
use App\Exceptions\ConflictException;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\ItemVariantService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The catalogue record: what a workshop deals in, and the rules about how it is
 * described.
 *
 * The test that matters most is the first one. A rewinding shop's catalogue holds
 * things that have almost nothing in common — a motor identified by its electrical
 * rating, a bearing identified by a size, copper measured out by weight, and an
 * hour of labour that cannot be held in stock at all. Getting all four into one
 * table without either forty null columns or a shapeless attribute bag is the whole
 * design problem of this module.
 */
class ItemTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function items(): ItemService
    {
        return app(ItemService::class);
    }

    private function variants(): ItemVariantService
    {
        return app(ItemVariantService::class);
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function inWorkshop(\Closure $callback): mixed
    {
        return $this->actingForTenant($this->tenant, $callback);
    }

    /* ---------------------------------------------------------------------
     | The four types coexisting
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_motor_a_bearing_copper_wire_and_labour_all_coexist(): void
    {
        $this->inWorkshop(function () {
            $motor = $this->items()->create([
                'name' => '3-Phase Induction Motor',
                'category_id' => $this->categoryId('motor'),
                'hsn_sac' => '8501',
                'gst_rate' => '18',
            ]);

            $bearing = $this->items()->create([
                'name' => 'Ball Bearing',
                'category_id' => $this->categoryId('part'),
                'hsn_sac' => '8482',
                'gst_rate' => '18',
            ]);

            $copper = $this->items()->create([
                'name' => 'Copper Winding Wire',
                'category_id' => $this->categoryId('bulk_material'),
                'hsn_sac' => '7408',
                'gst_rate' => '18',
            ]);

            $labour = $this->items()->create([
                'name' => 'Rewinding Labour',
                'category_id' => $this->categoryId('service'),
                'hsn_sac' => '998719',
                'gst_rate' => '18',
            ]);

            // Each is counted in the unit its trade actually uses, defaulted from
            // the type so nobody had to say so.
            $this->assertSame('piece', $motor->base_uom->value);
            $this->assertSame('piece', $bearing->base_uom->value);
            $this->assertSame('kg', $copper->base_uom->value);
            $this->assertSame('hour', $labour->base_uom->value);

            // And each is described by the fields its category asks for.
            $this->variants()->create($motor, ['attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440']]);
            $this->variants()->create($bearing, ['attributes' => ['size' => '6205']]);
            $this->variants()->create($copper, ['attributes' => ['gauge' => '22 SWG']]);
            $this->variants()->create($labour, []);

            $this->assertSame(4, Item::count());
            $this->assertSame(4, ItemVariant::count());
        });
    }

    #[Test]
    public function a_variant_label_reads_the_way_a_specification_is_recited(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            // Deliberately out of order on the way in: the schema decides the
            // order on the way out, so two variants described in different
            // sequences read identically.
            $variant = $this->variants()->create($motor, [
                'attributes' => ['rpm' => '1440', 'hp' => '5', 'phase' => '3'],
            ]);

            $this->assertSame('5 HP / 3 ph / 1440 RPM', $variant->displayLabel());
            $this->assertSame(['hp' => '5', 'phase' => '3', 'rpm' => '1440'], $variant->attributeBag());
        });
    }

    #[Test]
    public function a_workshops_own_label_wins_over_the_derived_one(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            $variant = $this->variants()->create($motor, [
                'label' => 'The small Crompton',
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ]);

            // A fitter asking for "the small Crompton" has to be able to find it
            // under that name.
            $this->assertSame('The small Crompton', $variant->displayLabel());
            // But the specification is still on the record.
            $this->assertSame('5 HP / 3 ph / 1440 RPM', $variant->derivedLabel());
        });
    }

    /* ---------------------------------------------------------------------
     | Attribute validation per type
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_motor_variant_without_its_rating_is_refused(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            $this->expectException(InvalidItemAttributesException::class);
            $this->expectExceptionMessageMatches('/needs its rating/i');

            $this->variants()->create($motor, ['attributes' => ['phase' => '3', 'rpm' => '1440']]);
        });
    }

    #[Test]
    public function copper_wire_needs_its_gauge_and_a_bearing_needs_its_size(): void
    {
        $this->inWorkshop(function () {
            $copper = Item::factory()->bulkMaterial()->create();
            $bearing = Item::factory()->part()->create();

            try {
                $this->variants()->create($copper, ['attributes' => ['grade' => 'ETP']]);
                $this->fail('Copper wire with no gauge should be refused.');
            } catch (InvalidItemAttributesException $exception) {
                $this->assertSame('ITEM_ATTRIBUTES_MISSING', $exception->errorCode());
                $this->assertSame(['gauge'], $exception->details()['missing']);
            }

            try {
                $this->variants()->create($bearing, ['attributes' => []]);
                $this->fail('A bearing with no size should be refused.');
            } catch (InvalidItemAttributesException $exception) {
                $this->assertSame(['size'], $exception->details()['missing']);
            }
        });
    }

    #[Test]
    public function optional_attributes_are_never_demanded(): void
    {
        $this->inWorkshop(function () {
            $bearing = Item::factory()->part()->create();

            // Material and brand are optional. Refusing a bearing because nobody
            // typed its material would push people into not recording the bearing.
            $variant = $this->variants()->create($bearing, ['attributes' => ['size' => '6205']]);

            $this->assertSame(['size' => '6205'], $variant->attributeBag());
        });
    }

    #[Test]
    public function an_attribute_the_type_does_not_recognise_is_refused(): void
    {
        $this->inWorkshop(function () {
            $bearing = Item::factory()->part()->create();

            $this->expectException(InvalidItemAttributesException::class);
            $this->expectExceptionMessageMatches('/not described by rpm/i');

            $this->variants()->create($bearing, ['attributes' => ['size' => '6205', 'rpm' => '1440']]);
        });
    }

    /**
     * An hour of rewinding is an hour of rewinding. An attribute bag on one would
     * only ever be filled in wrong.
     */
    #[Test]
    public function a_service_variant_takes_no_attributes_at_all(): void
    {
        $this->inWorkshop(function () {
            $labour = Item::factory()->service()->create();

            $plain = $this->variants()->create($labour, []);
            $this->assertNull($plain->attributes);
            $this->assertSame($labour->name, $plain->displayLabel());

            $this->expectException(InvalidItemAttributesException::class);
            // The refusal names the category rather than a fixed kind, and says
            // where the fields would be configured if it should have any.
            $this->expectExceptionMessageMatches('/no fields configured/i');

            $this->variants()->create($labour, ['attributes' => ['hp' => '5']]);
        });
    }

    #[Test]
    public function a_fixed_value_set_is_enforced_and_an_open_one_is_not(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            // Frame size is open: pinning it to a list would make the product
            // wrong about the next frame.
            $variant = $this->variants()->create($motor, [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440', 'frame' => '112M'],
            ]);

            $this->assertSame('112M', $variant->attribute('frame'));

            // Phase is not open: there is no third possibility.
            $this->expectException(InvalidItemAttributesException::class);
            $this->expectExceptionMessageMatches('/phase is one of 1, 3/i');

            $this->variants()->create($motor, [
                'attributes' => ['hp' => '7.5', 'phase' => '2', 'rpm' => '1440'],
            ]);
        });
    }

    #[Test]
    public function a_blank_attribute_is_treated_as_absent_rather_than_stored(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            // A form submits every field it renders. Storing an untouched box as
            // "" is noise that then has to be filtered out everywhere it is read.
            $variant = $this->variants()->create($motor, [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440', 'frame' => '  ', 'mounting' => null],
            ]);

            $this->assertSame(['hp' => '5', 'phase' => '3', 'rpm' => '1440'], $variant->attributeBag());
        });
    }

    #[Test]
    public function editing_attributes_revalidates_the_whole_bag(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();
            $variant = $this->variants()->create($motor, [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ]);

            // "I only sent the ones I changed" is indistinguishable from "I meant
            // to remove the rest", so a partial bag that drops a required field is
            // refused rather than merged into the old one.
            $this->expectException(InvalidItemAttributesException::class);

            $this->variants()->update($variant, ['attributes' => ['hp' => '7.5']]);
        });
    }

    /* ---------------------------------------------------------------------
     | Stock capability
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_service_item_can_never_hold_stock_however_it_is_asked(): void
    {
        $this->inWorkshop(function () {
            // Asked for explicitly, and overruled: an hour is produced at the
            // moment it is sold, so an opening balance of forty hours would be
            // inventing an asset that does not exist.
            $labour = $this->items()->create([
                'name' => 'Site Visit',
                'category_id' => $this->categoryId('service'),
                'is_stock' => true,
            ]);

            $this->assertFalse($labour->is_stock);
            $this->assertFalse($labour->tracksStock());
            $this->assertFalse($labour->category->holds_stock);

            // And it stays false through an edit.
            $edited = $this->items()->update($labour->id, ['is_stock' => true]);
            $this->assertFalse($edited->is_stock);
        });
    }

    /**
     * The asymmetry that makes `is_stock` worth having as a column at all:
     * capability comes from the type, the choice within it comes from the workshop.
     */
    #[Test]
    public function a_part_bought_to_order_may_be_marked_as_not_stocked(): void
    {
        $this->inWorkshop(function () {
            $part = $this->items()->create([
                'name' => 'Special Order Terminal Block',
                'category_id' => $this->categoryId('part'),
                'is_stock' => false,
            ]);

            $this->assertFalse($part->is_stock);
            $this->assertFalse($part->tracksStock());
            // But the category still could, which is the distinction M8 acts on.
            $this->assertTrue($part->category->holds_stock);
        });
    }

    #[Test]
    public function the_stocked_scope_finds_only_what_m8_has_to_count(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();
            $copper = Item::factory()->bulkMaterial()->create();
            $labour = Item::factory()->service()->create();
            $toOrder = Item::factory()->part()->create(['is_stock' => false, 'name' => 'To Order Part']);

            $stocked = Item::query()->stocked()->pluck('name')->all();

            $this->assertCount(2, $stocked);
            $this->assertNotContains('service', $stocked);

            // The variant scope M8 actually sweeps, which has to agree with the
            // item one: stock is counted per variant, never per family.
            $this->variants()->create($motor, ['attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440']]);
            $this->variants()->create($copper, ['attributes' => ['gauge' => '22 SWG']]);
            $this->variants()->create($labour, []);
            $this->variants()->create($toOrder, ['attributes' => ['size' => '6205']]);

            $this->assertSame(2, ItemVariant::query()->stocked()->count());
        });
    }

    /* ---------------------------------------------------------------------
     | Immutability of type and unit
     |-------------------------------------------------------------------- */

    /**
     * The same rule as an account's type, for the same reason: changing "each" to
     * "kilogram" would turn 40 pieces into 40 kilograms in every report ever run.
     */
    #[Test]
    public function the_type_and_the_unit_cannot_be_changed_after_the_fact(): void
    {
        $this->inWorkshop(function () {
            $item = Item::factory()->part()->create();

            $edited = $this->items()->update($item->id, [
                'category_id' => $this->categoryId('service'),
                'base_uom' => 'kg',
                'name' => 'Renamed Part',
            ]);

            $this->assertSame(
                $this->categoryId('part'),
                (int) $edited->category_id,
                'The category is not editable and must be ignored.',
            );
            $this->assertSame('piece', $edited->base_uom->value);
            $this->assertSame('Renamed Part', $edited->name, 'The edit that was allowed still applied.');
        });
    }

    /* ---------------------------------------------------------------------
     | Naming and codes
     |-------------------------------------------------------------------- */

    #[Test]
    public function two_items_cannot_share_a_name(): void
    {
        $this->inWorkshop(function () {
            $this->items()->create(['name' => 'Copper Wire', 'category_id' => $this->categoryId('bulk_material')]);

            $this->expectException(ConflictException::class);
            // Two rows called "Copper Wire" split one stock balance in half and
            // both halves look plausible.
            $this->expectExceptionMessageMatches('/split a single stock balance/i');

            $this->items()->create(['name' => 'Copper Wire', 'category_id' => $this->categoryId('bulk_material')]);
        });
    }

    #[Test]
    public function a_code_and_a_sku_are_upper_cased_and_unique(): void
    {
        $this->inWorkshop(function () {
            $item = $this->items()->create([
                'name' => 'Coded Motor',
                'category_id' => $this->categoryId('motor'),
                'code' => ' mot-3ph ',
            ]);

            $this->assertSame('MOT-3PH', $item->code);

            $variant = $this->variants()->create($item, [
                'sku' => 'mot-5hp-1440',
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ]);

            $this->assertSame('MOT-5HP-1440', $variant->sku);

            $second = $this->items()->create(['name' => 'Second Motor', 'category_id' => $this->categoryId('motor')]);

            $this->expectException(ConflictException::class);
            $this->expectExceptionMessageMatches('/identifies two things/i');

            $this->variants()->create($second, [
                'sku' => 'MOT-5HP-1440',
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ]);
        });
    }

    #[Test]
    public function a_code_is_optional_and_two_items_may_both_have_none(): void
    {
        $this->inWorkshop(function () {
            // A workshop that has never used codes should not have to invent one
            // to record its first item — and a unique index on a nullable column
            // must not make the second null a conflict.
            $this->items()->create(['name' => 'Uncoded One', 'category_id' => $this->categoryId('part')]);
            $this->items()->create(['name' => 'Uncoded Two', 'category_id' => $this->categoryId('part')]);

            $this->assertSame(2, Item::whereNull('code')->count());
        });
    }

    /* ---------------------------------------------------------------------
     | Duplicates
     |-------------------------------------------------------------------- */

    /**
     * Reported, never refused — the same treatment as a shared GSTIN in M5. Two 5 HP
     * / 1440 rows are usually one motor entered twice, but a workshop stocking two
     * brands at identical ratings legitimately has two.
     */
    #[Test]
    public function a_duplicate_specification_is_reported_but_allowed(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();
            $spec = ['hp' => '5', 'phase' => '3', 'rpm' => '1440'];

            $first = $this->variants()->create($motor, ['attributes' => $spec, 'label' => 'Crompton 5 HP']);
            $second = $this->variants()->create($motor, ['attributes' => $spec, 'label' => 'Kirloskar 5 HP']);

            $this->assertNotNull($second->id, 'The save succeeds — the duplicate is a warning, not a rule.');

            $others = $this->variants()->othersMatching($motor, $spec, $second->id);

            $this->assertSame([$first->id], $others->pluck('id')->all());
        });
    }

    /**
     * A second row is a duplicate whether or not somebody typed its optional
     * attributes, so the match is on the fields named rather than on the whole
     * document.
     */
    #[Test]
    public function a_duplicate_is_found_even_when_one_row_carries_extra_optional_detail(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            $withFrame = $this->variants()->create($motor, [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440', 'frame' => '112M'],
            ]);

            $others = $this->variants()->othersMatching(
                $motor,
                ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            );

            $this->assertSame([$withFrame->id], $others->pluck('id')->all());
        });
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    #[Test]
    public function draft_items_are_visible_in_a_review_queue_and_can_be_confirmed(): void
    {
        $this->inWorkshop(function () {
            Item::factory()->part()->draft()->count(3)->create();
            Item::factory()->part()->count(2)->create();

            $this->assertSame(3, $this->items()->draftCount());

            $draft = Item::query()->drafts()->first();
            $confirmed = $this->items()->update($draft->id, ['is_draft' => false]);

            $this->assertFalse($confirmed->is_draft);
            $this->assertSame(2, $this->items()->draftCount());
        });
    }

    /**
     * A draft item is a *real* item that stock may already have been posted
     * against — M11 imports opening stock against items it has just invented, and
     * hiding those would make the import unbalanced. The flag drives a worklist,
     * not a filter on the books.
     */
    #[Test]
    public function a_draft_item_is_a_usable_item(): void
    {
        $this->inWorkshop(function () {
            $draft = Item::factory()->bulkMaterial()->draft()->create();

            $variant = $this->variants()->create($draft, ['attributes' => ['gauge' => '22 SWG']]);

            $this->assertTrue($draft->is_active);
            $this->assertTrue($draft->tracksStock());
            // A variant of a draft item inherits the flag, so confirming the
            // family surfaces its variants for review too.
            $this->assertTrue($variant->is_draft);
        });
    }

    /* ---------------------------------------------------------------------
     | Deletion and archiving
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_item_with_variants_cannot_be_deleted(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();
            $this->variants()->create($motor, ['attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440']]);

            try {
                $this->items()->delete($motor->id);
                $this->fail('An item with variants should be refused.');
            } catch (ItemInUseException $exception) {
                $this->assertSame('ITEM_IN_USE', $exception->errorCode());
                // The refusal names the alternative rather than being a dead end.
                $this->assertTrue($exception->details()['archive_instead']);
            }

            // Archiving is always available, and leaves everything intact.
            $archived = $this->items()->update($motor->id, ['is_active' => false]);

            $this->assertFalse($archived->is_active);
            $this->assertSame(1, ItemVariant::where('item_id', $motor->id)->count());
        });
    }

    #[Test]
    public function an_item_nothing_points_at_can_be_deleted(): void
    {
        $this->inWorkshop(function () {
            $item = Item::factory()->part()->create();

            $this->items()->delete($item->id);

            $this->assertSame(0, Item::whereKey($item->id)->count());
        });
    }

    /* ---------------------------------------------------------------------
     | Prices
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_price_is_optional_and_never_defaulted_to_zero(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            // A motor rewind is quoted per job. A zero would say "free".
            $unpriced = $this->variants()->create($motor, [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
            ]);

            $this->assertNull($unpriced->sell_price);
            $this->assertNull($unpriced->sellPriceMoney());
        });
    }

    #[Test]
    public function a_price_is_stored_as_a_decimal_and_never_as_a_float(): void
    {
        $this->inWorkshop(function () {
            $motor = Item::factory()->motor()->create();

            // 0.1 + 0.2 is the canonical float failure; a price that survives
            // json_decode's float is what this asserts.
            $variant = $this->variants()->create($motor, [
                'attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440'],
                'sell_price' => 12345.10,
            ]);

            $this->assertSame('12345.10', (string) $variant->sell_price);
            $this->assertTrue($variant->sellPriceMoney()->equals(Money::of('12345.10')));
        });
    }

    /**
     * A markup is a *suggestion* computed from a cost passed in, never from a
     * stored one: cost is M8's weighted average at the moment of sale, and a margin
     * held here would be stale the next time stock arrived.
     */
    #[Test]
    public function a_markup_suggests_a_price_from_a_cost_it_is_given(): void
    {
        $this->inWorkshop(function () {
            $copper = Item::factory()->bulkMaterial()->create();

            $variant = $this->variants()->create($copper, [
                'attributes' => ['gauge' => '22 SWG'],
                'markup_percent' => '25',
            ]);

            $this->assertSame('875.00', $variant->suggestedPriceFrom(Money::of('700.00'))->amount());
            $this->assertSame('1000.00', $variant->suggestedPriceFrom(Money::of('800.00'))->amount());
        });
    }

    #[Test]
    public function a_reorder_level_is_rounded_to_what_its_unit_allows(): void
    {
        $this->inWorkshop(function () {
            $copper = Item::factory()->bulkMaterial()->create();
            $bearing = Item::factory()->part()->create();

            $measured = $this->variants()->create($copper, [
                'attributes' => ['gauge' => '22 SWG'],
                'reorder_level' => '12.5',
            ]);

            $counted = $this->variants()->create($bearing, [
                'attributes' => ['size' => '6205'],
                // 2.5 bearings is a mistake, not a quantity.
                'reorder_level' => '2.5',
            ]);

            $this->assertSame('12.500', (string) $measured->reorder_level);
            $this->assertSame('3.000', (string) $counted->reorder_level);
        });
    }

    /* ---------------------------------------------------------------------
     | Tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_catalogue_is_scoped_to_its_workshop(): void
    {
        $other = Tenant::factory()->create();

        $mine = $this->inWorkshop(fn () => Item::factory()->create(['name' => 'My Bearing']));
        $theirs = $this->actingForTenant($other, fn () => Item::factory()->create(['name' => 'Their Bearing']));

        $this->assertSame(['My Bearing'], $this->inWorkshop(fn () => Item::pluck('name')->all()));
        $this->assertSame(['Their Bearing'], $this->actingForTenant($other, fn () => Item::pluck('name')->all()));

        // The same name is available to both workshops, which is what per-tenant
        // uniqueness means.
        $this->actingForTenant($other, fn () => Item::factory()->create(['name' => 'My Bearing']));

        $this->assertSame(2, $this->inWorkshop(fn () => Item::withoutGlobalScopes()->where('name', 'My Bearing')->count()));

        // And another workshop's item does not resolve here.
        $this->assertNull($this->inWorkshop(fn () => Item::find($theirs->id)));
        $this->assertNotNull($this->inWorkshop(fn () => Item::find($mine->id)));
    }

    #[Test]
    public function a_variant_cannot_be_reached_from_another_workshop(): void
    {
        $other = Tenant::factory()->create();

        $theirVariant = $this->actingForTenant($other, function () {
            $item = Item::factory()->motor()->create();

            return $this->variants()->create($item, ['attributes' => ['hp' => '5', 'phase' => '3', 'rpm' => '1440']]);
        });

        $this->assertNull($this->inWorkshop(fn () => ItemVariant::find($theirVariant->id)));
    }
}
