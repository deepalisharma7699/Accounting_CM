<?php

namespace App\Casts;

use App\Support\Units\UnitDefinition;
use App\Support\Units\UnitRegistry;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a stored unit code into a {@see UnitDefinition}, and back.
 *
 * This is the whole of the compatibility story. `items.base_uom`,
 * `transaction_lines.unit` and `workshop_job_parts.unit` were cast to the
 * `UnitOfMeasure` enum; they are cast to this instead, and because a
 * `UnitDefinition` answers `->value`, `->label()`, `->symbol()`,
 * `->isFractional()` and `->quantityScale()` exactly as the enum did, not one of
 * the call sites reading those had to change.
 *
 * The column itself is untouched — still the same string, still `'piece'` and
 * `'kg'`. That matters most for `transaction_lines`, whose unit is a copy of what
 * was true when the invoice was issued rather than a live reference. See the
 * units migration.
 *
 * @implements CastsAttributes<UnitDefinition, UnitDefinition|string>
 */
class UnitCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?UnitDefinition
    {
        if ($value === null) {
            return null;
        }

        return app(UnitRegistry::class)->get((string) $value);
    }

    /**
     * Accepts either the definition or the bare code.
     *
     * Both, because a service that has just resolved a unit hands over the
     * object while an importer reading a spreadsheet has only the string, and
     * making one of them convert for the other would be a conversion somebody
     * eventually forgets.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof UnitDefinition) {
            return $value->value;
        }

        return trim((string) $value);
    }
}
