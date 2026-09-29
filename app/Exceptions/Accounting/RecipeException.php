<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * The refusals a recipe makes — what a made thing may be made out of.
 *
 * They are all one idea: **a recipe turns one bill line into several stock
 * issues, so anything it is allowed to say wrong is a quantity taken off the
 * wrong shelf, for ever, with a plausible-looking invoice on top of it.** A
 * wrong recipe is not discovered by reading it; it is discovered at a stock
 * take, months later, as a shortage nobody can account for.
 *
 * So the refusals are made at the moment somebody writes one, where the remedy
 * is in front of them, rather than at the moment a bill is posted, where the
 * only thing to do about it is abandon the sale with a customer at the counter.
 */
class RecipeException extends ApiException
{
    /**
     * A recipe on something that is itself counted on a shelf.
     *
     * Refused rather than resolved, because there is no good resolution: billing
     * it would either issue the parent, or its materials, or both, and each of
     * those is a defensible reading of the same row. A parent that *is* stocked
     * is a kit, and a kit needs an assembly document to put it on the shelf in
     * the first place — a different feature, deliberately not half-built here.
     */
    public static function parentHoldsStock(string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" is counted in stock, so it cannot also be made from a recipe. A recipe belongs to '.
                'something the workshop produces rather than holds — labour, a rewind, a service. '.
                'Turn stock tracking off for it, or record the materials as their own lines on the bill.',
                $name,
            ),
            status: 422,
            errorCode: 'RECIPE_PARENT_HOLDS_STOCK',
            details: ['parent' => $name],
        );
    }

    /**
     * A material that is not counted anywhere.
     *
     * Nothing could be issued for it, so the row would be a quantity of a thing
     * with no shelf — which reads on the screen as though it were being
     * consumed and takes nothing off anything.
     */
    public static function componentHoldsNoStock(string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" is not counted in stock, so there is nothing for a recipe to take off the shelf. '.
                'Only a material with a stock position can be consumed.',
                $name,
            ),
            status: 422,
            errorCode: 'RECIPE_COMPONENT_HOLDS_NO_STOCK',
            details: ['component' => $name],
        );
    }

    /**
     * A material that is itself made from a recipe.
     *
     * One level, and refused rather than resolved. Expanding a recipe inside a
     * recipe means walking a graph at the moment a bill is posted, where a cycle
     * somebody entered months earlier stops being a data problem and becomes a
     * counter that hangs mid-sale. A workshop that genuinely assembles a
     * sub-assembly should record it as its own document.
     */
    public static function componentHasOwnRecipe(string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" is itself made from a recipe, and a recipe cannot contain another one. '.
                'List what it is made of directly instead.',
                $name,
            ),
            status: 422,
            errorCode: 'RECIPE_WOULD_NEST',
            details: ['component' => $name],
        );
    }

    public static function componentIsItself(string $name): self
    {
        return new self(
            message: sprintf('"%s" cannot be made out of itself.', $name),
            status: 422,
            errorCode: 'RECIPE_SELF_REFERENCE',
            details: ['component' => $name],
        );
    }

    /**
     * The same material twice on one recipe.
     *
     * Refused rather than summed: two rows of copper are a quantity somebody
     * meant to type once, and adding them silently would consume twice what the
     * screen appeared to say on the row they were looking at.
     */
    public static function duplicateComponent(string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" is on this recipe twice. Put the whole quantity on one row — two rows would '.
                'both be issued.',
                $name,
            ),
            status: 422,
            errorCode: 'RECIPE_DUPLICATE_COMPONENT',
            details: ['component' => $name],
        );
    }

    public static function quantityNotPositive(string $name): self
    {
        return new self(
            message: sprintf(
                'How much "%s" does one of these consume? A recipe row needs a quantity above nought — '.
                'remove the row if nothing is used.',
                $name,
            ),
            status: 422,
            errorCode: 'RECIPE_QUANTITY_NOT_POSITIVE',
            details: ['component' => $name],
        );
    }

    /**
     * A material that has been archived.
     *
     * Existing rows go on working — a recipe written last year still says what
     * the workshop used, and a posted bill is unaffected either way — but a new
     * one may not be written against something the catalogue has retired.
     */
    public static function componentArchived(string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" has been archived, so it cannot be added to a recipe. Restore it, or choose the '.
                'material that replaced it.',
                $name,
            ),
            status: 422,
            errorCode: 'RECIPE_COMPONENT_ARCHIVED',
            details: ['component' => $name],
        );
    }

    /**
     * The table is not there yet.
     *
     * §4.6: the schema step is SQL an operator runs by hand, so there is a
     * window in which this code is deployed and the table is not. Reads answer
     * "no recipe" in that window, which is exactly what was true before — but a
     * *write* must say plainly what is missing rather than fail as a database
     * error nobody outside the server can interpret.
     */
    public static function notInstalled(): self
    {
        return new self(
            message: 'Recipes are not switched on for this installation yet. The schema step '.
                '(database/manual/sql/2026_09_29_001_item_components.sql) has not been run on this server.',
            status: 503,
            errorCode: 'RECIPE_SCHEMA_MISSING',
        );
    }
}
