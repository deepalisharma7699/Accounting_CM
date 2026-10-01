<?php

namespace App\Contracts;

use App\Models\AttributeDefinition;
use Illuminate\Support\Collection;

/**
 * Something that declares what to record about the things filed under it.
 *
 * Implemented by {@see \App\Models\ItemCategory} — what to ask about a product
 * — and {@see \App\Models\JobKind} — what to ask about a thing on the bench.
 * They are separate lists on purpose and the machinery behind them is not; this
 * is what lets a caller that only needs "the questions, in order" accept either
 * without knowing which it has.
 *
 * {@see \App\Models\Concerns\DefinesAQuestionSet} is the one implementation of
 * everything below except `resolvedAttributes()`, which is the one place the
 * two genuinely differ: a category walks its parent chain, a kind has none.
 */
interface QuestionSet
{
    /**
     * Every field a record of this kind is asked for, in the order a form
     * should draw them.
     *
     * @return Collection<int, AttributeDefinition>
     */
    public function resolvedAttributes(bool $activeOnly = true): Collection;

    /**
     * The same set in the shape the universal form reads.
     *
     * @return array<string, array<string, mixed>>
     */
    public function attributeSchema(): array;

    /**
     * @return array<int, string>
     */
    public function requiredAttributeKeys(): array;
}
