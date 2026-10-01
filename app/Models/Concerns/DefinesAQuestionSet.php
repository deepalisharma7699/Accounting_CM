<?php

namespace App\Models\Concerns;

use App\Models\AttributeDefinition;
use Illuminate\Support\Collection;

/**
 * Turns a resolved list of fields into the shape a form reads.
 *
 * Shared by {@see \App\Models\ItemCategory} and {@see \App\Models\JobKind},
 * which are two *lists* over one piece of machinery: what a workshop sells and
 * what a customer wheels through the door barely overlap, but "here are the
 * questions, in order, in the shape the universal form reads" is one answer and
 * must stay one.
 *
 * What a user of this trait supplies is `resolvedAttributes()` — the fields, in
 * the order a form should draw them. A category walks its parent chain to get
 * there; a kind has no chain and simply returns its own. That difference is the
 * whole of why this is a trait and not a base class.
 */
trait DefinesAQuestionSet
{
    /**
     * Every field a record of this kind is asked for, in the order a form should
     * draw them.
     *
     * @return Collection<int, AttributeDefinition>
     */
    abstract public function resolvedAttributes(bool $activeOnly = true): Collection;

    /**
     * The question set in the shape the universal form reads — and the shape
     * `ItemType::attributeSchema()` used to return, key for key.
     *
     * That compatibility is deliberate and is why the front end did not have to
     * be rewritten: it already built its inputs from the server's answer, so
     * changing where the answer comes from changed nothing it could see. It is
     * also why the bench could be given its own kinds without touching
     * `components/attribute-fields.js` at all.
     *
     * @return array<string, array<string, mixed>>
     */
    public function attributeSchema(): array
    {
        $schema = [];

        foreach ($this->resolvedAttributes() as $attribute) {
            $schema[$attribute->key] = $attribute->toSchemaField();
        }

        return $schema;
    }

    /**
     * The keys a record of this kind cannot exist without.
     *
     * Honoured for a product and deliberately ignored for a job — see
     * {@see \App\Models\JobKindAttribute} for why a bench cannot demand a field.
     *
     * @return array<int, string>
     */
    public function requiredAttributeKeys(): array
    {
        return $this->resolvedAttributes()
            ->filter(fn (AttributeDefinition $attribute) => $attribute->is_required)
            ->map(fn (AttributeDefinition $attribute) => $attribute->key)
            ->values()
            ->all();
    }
}
