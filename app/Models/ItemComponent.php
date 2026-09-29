<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One material on a recipe: what a made thing consumes, and how much of it.
 *
 * A rewind is sold as one line at one price, and it eats copper, varnish and
 * sleeve off the shelf. The catalogue could not say so: an item either held
 * stock of its own or held none, and a service that held none took nothing out
 * of anything. So the wire was bought, was never issued, and the shelf, the
 * Inventory account and every margin the workshop reported were wrong together
 * and in the same direction.
 *
 * ## It hangs off the variant, not the item
 *
 * A 5 HP rewind and a 10 HP rewind are the same service and different
 * quantities of copper. That is the same reason stock is counted per variant
 * rather than per item, applied to the other end of the same relationship.
 *
 * ## Only something that holds no stock may have one
 *
 * Enforced by {@see \App\Services\Inventory\ItemComponentService}. A recipe on
 * a *stocked* parent would leave a question with no good answer — does billing
 * it issue the parent, or the parts, or both? — and any answer to that is a
 * kit, which needs an assembly document to put the kit on the shelf in the
 * first place. That is a different feature; this is deliberately not half of
 * it.
 *
 * ## Nothing posted ever reads this again
 *
 * A recipe is expanded once, at the moment a bill is posted, into ordinary
 * stock movements written by the posting engine. Those movements are the record
 * of what was consumed, so a reversal, a return and a margin all read them and
 * none of them consults the recipe. Correcting a recipe next March therefore
 * cannot restate what a bill in September took off the shelf — which is why
 * there is no copy of it pinned to the bill line, and must not become one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $parent_variant_id
 * @property int $component_variant_id
 * @property string $quantity
 */
#[Fillable([
    'tenant_id', 'parent_variant_id', 'component_variant_id', 'quantity',
])]
class ItemComponent extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'item_components';

    /**
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['parent_variant_id', 'component_variant_id', 'quantity'];
    }

    public function auditLabel(): string
    {
        $parent = $this->relationLoaded('parentVariant') ? $this->parentVariant?->displayLabel() : null;
        $component = $this->relationLoaded('componentVariant') ? $this->componentVariant?->displayLabel() : null;

        if ($parent === null || $component === null) {
            return "Recipe #{$this->id}";
        }

        return sprintf('%s consumes %s', $parent, $component);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * The thing being made.
     *
     * @return BelongsTo<ItemVariant, $this>
     */
    public function parentVariant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'parent_variant_id');
    }

    /**
     * The material it is made out of.
     *
     * @return BelongsTo<ItemVariant, $this>
     */
    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'component_variant_id');
    }

    /* ---------------------------------------------------------------------
     | Amounts
     |-------------------------------------------------------------------- */

    /**
     * How much of the material one of the parent consumes.
     */
    public function quantityValue(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    /**
     * How much of it `$made` of the parent consume.
     *
     * Multiplied through {@see Quantity}, which works in integer thousandths —
     * `2.5 × 3` in a float is not reliably `7.5`, and `decimal(15,3)` refuses
     * what comes out of one. The same arithmetic, in the same place, that the
     * stock adjustment component already does for the other direction.
     */
    public function quantityFor(Quantity $made): Quantity
    {
        return $this->quantityValue()->times($made);
    }
}
