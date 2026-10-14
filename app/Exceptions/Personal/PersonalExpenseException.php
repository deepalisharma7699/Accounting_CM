<?php

namespace App\Exceptions\Personal;

use App\Exceptions\ApiException;

/**
 * The refusals the personal diary can give, each with a sentence somebody can
 * act on.
 */
class PersonalExpenseException extends ApiException
{
    /**
     * The tables come from database/manual/sql, which an operator runs by hand
     * (§4.6), so the code can be live before they exist. A clean refusal here
     * rather than a SQL error in the response.
     */
    public static function notInstalled(): self
    {
        return new self(
            message: 'Personal expenses are not set up on this server yet. The database step '.
                '(database/manual/sql/2026_10_14_001_personal_expenses.sql) has not been run.',
            status: 503,
            errorCode: 'PERSONAL_EXPENSES_NOT_INSTALLED',
        );
    }

    public static function categoryInUse(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '%d entr%s filed under "%s", so it cannot be deleted. Archive it instead: it stops '.
                'appearing on the form and the entries keep it.',
                $count,
                $count === 1 ? 'y is' : 'ies are',
                $name,
            ),
            status: 409,
            errorCode: 'PERSONAL_CATEGORY_IN_USE',
            details: ['category_id' => $id, 'expense_count' => $count, 'archive_instead' => true],
        );
    }

    public static function categoryArchived(string $name): self
    {
        return new self(
            message: "\"{$name}\" is archived. Restore it from the category list, or choose another.",
            status: 422,
            errorCode: 'PERSONAL_CATEGORY_ARCHIVED',
            details: ['fields' => ['category_id' => ["\"{$name}\" is archived."]]],
        );
    }

    /**
     * Another workshop's id, or one deleted since the form was drawn. A 422 on
     * the field rather than a 404: the request is about this entry, which
     * exists, and the person needs to be shown which box to change.
     */
    public static function unknownCategory(): self
    {
        return new self(
            message: 'That category no longer exists. Choose another.',
            status: 422,
            errorCode: 'PERSONAL_CATEGORY_UNKNOWN',
            details: ['fields' => ['category_id' => ['That category no longer exists.']]],
        );
    }

    public static function nameTaken(string $name): self
    {
        return new self(
            message: "There is already a category called \"{$name}\" — use that one.",
            status: 409,
            errorCode: 'PERSONAL_CATEGORY_NAME_TAKEN',
            details: ['fields' => ['name' => ['That name is already on the list.']]],
        );
    }
}
