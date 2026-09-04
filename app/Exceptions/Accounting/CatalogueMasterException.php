<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * The refusals the Category, Brand and Unit masters make.
 *
 * They are all one idea: **a definition that something already depends on may be
 * switched off, but not removed.** A category deleted out from under its products
 * would leave them describing themselves in terms nobody can resolve; a unit
 * deleted out from under a posted invoice would leave "12" on a document with no
 * word after it.
 *
 * Every message says what is in the way and what to do instead, because the
 * remedy is never obvious from the refusal alone — "archive it" is a different
 * button from the one they just pressed.
 */
class CatalogueMasterException extends ApiException
{
    /* ---------------------------------------------------------------------
     | Categories
     |-------------------------------------------------------------------- */

    public static function categoryHasItems(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: %d %s filed under it. Archive the category instead — '.
                'it will stop appearing on the create form and go on explaining the products already using it.',
                $name,
                $count,
                $count === 1 ? 'product is' : 'products are',
            ),
            status: 409,
            errorCode: 'CATEGORY_IN_USE',
            details: ['category_id' => $id, 'items' => $count],
        );
    }

    public static function categoryHasChildren(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: %d %s sit under it. Delete or move those first — '.
                'removing a parent would leave them asking for fields nothing defines.',
                $name,
                $count,
                $count === 1 ? 'subcategory' : 'subcategories',
            ),
            status: 409,
            errorCode: 'CATEGORY_HAS_CHILDREN',
            details: ['category_id' => $id, 'children' => $count],
        );
    }

    /**
     * The four seeded rows that were `ItemType`. Renameable, archivable, never
     * deletable — products and posted documents already refer to what they mean.
     */
    public static function categoryProtected(int $id, string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" is one of the categories the system was set up with and cannot be deleted. '.
                'Archive it instead if the shop no longer deals in it.',
                $name,
            ),
            status: 409,
            errorCode: 'CATEGORY_PROTECTED',
            details: ['category_id' => $id],
        );
    }

    /**
     * A category cannot be its own ancestor. MySQL will not express this as a
     * CHECK against an auto-increment column, so it is refused here — see the
     * category migration.
     */
    public static function categoryCycle(string $name): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot sit under itself or under one of its own subcategories — '.
                'the fields it inherits would have no top to start from.',
                $name,
            ),
            status: 422,
            errorCode: 'CATEGORY_CYCLE',
            details: ['field' => 'parent_id'],
        );
    }

    public static function categoryNameTaken(string $name): self
    {
        return new self(
            message: sprintf(
                'A category named "%s" already exists. Two categories of one name would split a '.
                'product range in half and both halves would look right.',
                $name,
            ),
            status: 409,
            errorCode: 'CATEGORY_NAME_TAKEN',
            details: ['field' => 'name'],
        );
    }

    /**
     * Turning off `holds_stock` for a category that has stocked products under it.
     *
     * Refused rather than cascaded. Silently setting `is_stock = false` on twelve
     * products would take them off the stock report with no record of why, and
     * their movements would stay in the ledger describing quantities of something
     * the catalogue now says is not stocked.
     */
    public static function categoryStillStocks(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" still has %d stocked %s under it, so it cannot be marked as holding no stock. '.
                'Turn stock tracking off on those products first, or archive them.',
                $name,
                $count,
                $count === 1 ? 'product' : 'products',
            ),
            status: 409,
            errorCode: 'CATEGORY_STILL_STOCKS',
            details: ['category_id' => $id, 'items' => $count, 'field' => 'holds_stock'],
        );
    }

    /* ---------------------------------------------------------------------
     | Attributes
     |-------------------------------------------------------------------- */

    /**
     * An attribute whose key is already defined by an ancestor category.
     *
     * Two definitions of one JSON field, and the resolver would have to pick one
     * — which means the other silently does nothing.
     */
    public static function attributeKeyInherited(string $key, string $from): self
    {
        return new self(
            message: sprintf(
                '"%s" is already asked for by %s, which this category inherits from. '.
                'Edit it there and every category under it changes with it.',
                $key,
                $from,
            ),
            status: 409,
            errorCode: 'ATTRIBUTE_KEY_INHERITED',
            details: ['field' => 'key', 'key' => $key, 'inherited_from' => $from],
        );
    }

    public static function attributeKeyTaken(string $key): self
    {
        return new self(
            message: sprintf('This category already has a field called "%s".', $key),
            status: 409,
            errorCode: 'ATTRIBUTE_KEY_TAKEN',
            details: ['field' => 'key', 'key' => $key],
        );
    }

    /**
     * Deleting a field that products have already answered.
     *
     * The values stay in their bags either way — nothing rewrites a thousand
     * variants — so the choice is between a field that explains them and no field
     * at all. Switching it off keeps the explanation.
     */
    public static function attributeHasValues(int $id, string $label, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: %d %s already recorded a value for it. Switch it off instead — '.
                'it will stop appearing on the form and go on explaining what those values mean.',
                $label,
                $count,
                $count === 1 ? 'product has' : 'products have',
            ),
            status: 409,
            errorCode: 'ATTRIBUTE_IN_USE',
            details: ['attribute_id' => $id, 'variants' => $count],
        );
    }

    /**
     * Making a field required when products already exist without it.
     *
     * Those products would be invalid the moment anybody opened them for editing,
     * and the person who tripped over it would have no idea why.
     */
    public static function attributeCannotBeRequired(string $label, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be made compulsory: %d existing %s no value for it, and every one of them '.
                'would be refused on the next edit. Fill them in first, or leave the field optional.',
                $label,
                $count,
                $count === 1 ? 'product has' : 'products have',
            ),
            status: 409,
            errorCode: 'ATTRIBUTE_CANNOT_BE_REQUIRED',
            details: ['field' => 'is_required', 'variants' => $count],
        );
    }

    /**
     * A dropdown whose option list no longer covers values already recorded.
     */
    public static function attributeOptionsInUse(string $label, array $orphaned): self
    {
        return new self(
            message: sprintf(
                '"%s" still has products recorded as %s. Removing those choices would leave them '.
                'holding a value the field says is impossible.',
                $label,
                implode(', ', array_map(static fn ($value) => '"'.$value.'"', $orphaned)),
            ),
            status: 409,
            errorCode: 'ATTRIBUTE_OPTIONS_IN_USE',
            details: ['field' => 'options', 'orphaned' => $orphaned],
        );
    }

    /* ---------------------------------------------------------------------
     | Brands
     |-------------------------------------------------------------------- */

    /**
     * A brand products already carry.
     *
     * Refused rather than cascaded, and archiving offered instead. Clearing
     * `brand_id` on twelve products would silently make twelve unbranded things
     * out of twelve Cromptons, with nothing anywhere to say it happened.
     */
    public static function brandInUse(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: %d %s it. Archive the brand instead — '.
                'it will stop appearing on the create form and go on naming the products already using it.',
                $name,
                $count,
                $count === 1 ? 'product carries' : 'products carry',
            ),
            status: 409,
            errorCode: 'BRAND_IN_USE',
            details: ['brand_id' => $id, 'items' => $count],
        );
    }

    public static function brandNameTaken(string $name): self
    {
        return new self(
            message: sprintf(
                'A brand named "%s" already exists. Two brands of one name would split a product '.
                'range in half and both halves would look right.',
                $name,
            ),
            status: 409,
            errorCode: 'BRAND_NAME_TAKEN',
            details: ['field' => 'name'],
        );
    }

    public static function brandCodeTaken(string $code): self
    {
        return new self(
            message: sprintf('A brand coded "%s" already exists.', $code),
            status: 409,
            errorCode: 'BRAND_CODE_TAKEN',
            details: ['field' => 'code'],
        );
    }

    /* ---------------------------------------------------------------------
     | Units
     |-------------------------------------------------------------------- */

    /**
     * A unit something is counted in.
     *
     * Checked against products, posted bill lines and job parts alike: a unit is
     * copied onto a document at the moment it is issued, and the copy is what
     * makes "12" mean twelve kilograms a year later.
     */
    public static function unitInUse(int $id, string $label, int $count, string $where): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be deleted: it is the unit on %d %s. Switch it off instead — '.
                'it will vanish from the pickers and go on explaining the quantities already recorded in it.',
                $label,
                $count,
                $where,
            ),
            status: 409,
            errorCode: 'UNIT_IN_USE',
            details: ['unit_id' => $id, 'used_by' => $count, 'where' => $where],
        );
    }

    public static function unitProtected(int $id, string $label): self
    {
        return new self(
            message: sprintf(
                '"%s" is one of the units the system was set up with and cannot be deleted. '.
                'Switch it off instead if the shop never uses it.',
                $label,
            ),
            status: 409,
            errorCode: 'UNIT_PROTECTED',
            details: ['unit_id' => $id],
        );
    }

    public static function unitCodeTaken(string $code): self
    {
        return new self(
            message: sprintf(
                'A unit coded "%s" already exists. A code that identifies two units would '.
                'make every quantity recorded in it ambiguous.',
                $code,
            ),
            status: 409,
            errorCode: 'UNIT_CODE_TAKEN',
            details: ['field' => 'code'],
        );
    }

    public static function unitLabelTaken(string $label): self
    {
        return new self(
            message: sprintf('A unit named "%s" already exists.', $label),
            status: 409,
            errorCode: 'UNIT_LABEL_TAKEN',
            details: ['field' => 'label'],
        );
    }

    /**
     * Narrowing a unit's scale below what quantities already recorded in it use.
     *
     * 12.5 kg exists; setting kilograms to whole numbers would make it
     * unrepresentable, and every screen would round it to 13 without saying so.
     */
    public static function unitScaleTooNarrow(string $label, string $example): self
    {
        return new self(
            message: sprintf(
                '"%s" cannot be limited to whole numbers: quantities like %s are already recorded in it, '.
                'and they would be rounded silently on every screen that showed them.',
                $label,
                $example,
            ),
            status: 409,
            errorCode: 'UNIT_SCALE_TOO_NARROW',
            details: ['field' => 'decimals', 'example' => $example],
        );
    }
}
