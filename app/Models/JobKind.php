<?php

namespace App\Models;

use App\Contracts\QuestionSet;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\DefinesAQuestionSet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A kind of thing that comes in for repair, and what to ask about it.
 *
 * ## Why this is not `item_categories`
 *
 * It was, and the bug it caused is the reason this table exists. The intake
 * form's Kind list was every active category with `holds_stock` on — a filter
 * chosen because "a category that holds no stock is produced at the moment it
 * is sold, and nobody wheels one of those through a door". True, and beside the
 * point: `holds_stock` separates *kept on a shelf* from *made when sold*, which
 * says nothing about whether a customer brings one in. So the bench offered
 * Part, Bulk material, Bearing, Capacitor and Wire — none of which anybody
 * brings in — and offered no cooler, fan, mixer or submersible at all, because
 * a repair shop does not stock the things it repairs.
 *
 * The intersection of the two lists is Motor and pumps. Everything else on each
 * side is noise to the other, and even the overlap wants different questions: a
 * motor on a price list is identified by HP, phase, frame and mounting, while a
 * motor on a bench wants its serial number, its winding condition and what the
 * customer says is wrong with it. Two lists that only looked alike.
 *
 * What is emphatically *not* duplicated is the machinery — see
 * {@see AttributeDefinition} and {@see DefinesAQuestionSet}. One renderer draws
 * both forms, one resolver answers both, and this class is deliberately thinner
 * than {@see ItemCategory} by everything that describes a thing for *sale*: no
 * HSN or SAC, no GST rate, no default unit, no `holds_stock`.
 *
 * ## And no parent
 *
 * A category tree earns its complexity on a catalogue of hundreds of products,
 * where "Submersible Motor" inherits a motor's question set and adds Head and
 * Flow Rate. A bench takes in eight or ten kinds of thing and a cooler inherits
 * nothing from a fan. {@see resolvedAttributes()} therefore walks no chain,
 * which is the one place this differs from a category and the reason the shared
 * piece is a trait rather than a base class.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_active
 * @property int $display_order
 */
#[Fillable([
    'tenant_id', 'name', 'description', 'is_system', 'is_active', 'display_order',
])]
class JobKind extends Model implements QuestionSet
{
    use Auditable, BelongsToTenant, DefinesAQuestionSet;

    /**
     * `is_active` is the one that changes records without touching one: a kind
     * switched off stops being offered, and every job booked in afterwards is
     * filed under something else.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['name', 'description', 'is_active', 'display_order'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * The questions this kind asks.
     *
     * `fields()` and not `attributes()`, for the reason {@see ItemCategory}
     * records: `$model->attributes` is Eloquent's own raw column bag on every
     * model in the framework, so a relation of that name loads through `with()`
     * and then silently returns the column array on property access — and the
     * schema resolves to nothing while every kind looks as though it has no
     * fields at all.
     *
     * @return HasMany<JobKindAttribute, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(JobKindAttribute::class, 'job_kind_id')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<WorkshopJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(WorkshopJob::class, 'job_kind_id');
    }

    /* ---------------------------------------------------------------------
     | The resolved question set
     |-------------------------------------------------------------------- */

    /**
     * Its own fields, in its own order. No chain to walk — see the class note.
     *
     * @return Collection<int, JobKindAttribute>
     */
    public function resolvedAttributes(bool $activeOnly = true): Collection
    {
        return $this->fields
            ->filter(fn (JobKindAttribute $field) => ! $activeOnly || $field->is_active)
            ->values();
    }

    /**
     * Whether this is one of the seeded rows.
     *
     * They may be renamed, re-described and switched off; they may not be
     * deleted, because jobs already booked in refer to what they mean.
     */
    public function isProtected(): bool
    {
        return (bool) $this->is_system;
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
