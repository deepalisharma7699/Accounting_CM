<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Units\UnitDefinition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the Unit Master — how a thing is counted.
 *
 * This is the *record*, edited from the Units screen. What the rest of the
 * application reads is {@see UnitDefinition}, handed out by
 * {@see \App\Support\Units\UnitRegistry}: `$item->base_uom` is on the hot path of
 * every listing and every bill line, and resolving a model there would be a query
 * for a fact that fits in memory whole.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $label
 * @property string $symbol
 * @property string $kind
 * @property int $decimals
 * @property bool $is_system
 * @property bool $is_active
 * @property int $display_order
 */
#[Fillable([
    'tenant_id', 'code', 'label', 'symbol', 'kind',
    'decimals', 'is_system', 'is_active', 'display_order',
])]
class Unit extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * `code` is listed although it is never editable, for the reason `items.type`
     * is listed on the item: it is what makes a deletion snapshot mean anything
     * afterwards. "Kilogram" tells a reader nothing; "kg, weight, 3 places" tells
     * them what the numbers meant.
     *
     * `decimals` is the one to watch. Widening it retrospectively permits a
     * quantity that was refused an hour earlier — half a bearing — so a reader
     * comparing two months needs to know it moved.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['code', 'label', 'symbol', 'kind', 'decimals', 'is_active', 'display_order'];
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'display_order' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The value object the rest of the application actually reads.
     */
    public function toDefinition(): UnitDefinition
    {
        return new UnitDefinition(
            value: $this->code,
            label: $this->label,
            symbol: $this->symbol,
            kind: $this->kind,
            decimals: (int) $this->decimals,
        );
    }

    /**
     * Whether a part of one is a meaningful quantity — 2.5 kg against 2.5
     * bearings. Derived from the scale rather than stored beside it, so the two
     * can never disagree.
     */
    public function isFractional(): bool
    {
        return $this->decimals > 0;
    }

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
        return $query->orderBy('display_order')->orderBy('label');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
