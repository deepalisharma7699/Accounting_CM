<?php

namespace App\Services\Personal;

/**
 * The categories an owner's personal diary starts with.
 *
 * A starting point and nothing more: every one can be renamed, archived or
 * deleted, and "Pet care" is one tap away. Kept here in one place because two
 * callers need the identical list — {@see PersonalCategoryProvisioner} for a new
 * workshop, and the manual SQL step for the workshops that already existed
 * (database/manual/sql/2026_10_14_001_personal_expenses.sql), which writes the
 * same twelve names out by hand. Change one, change the other.
 */
final class PersonalCategoryDefaults
{
    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [
            'Household & groceries',
            'Home rent',
            'Home utilities',
            'Fuel & travel',
            "Children's education",
            'Medical',
            'Mobile & internet',
            'Clothing & shopping',
            'Functions & gifts',
            'Dining & entertainment',
            'Loan EMI & insurance',
            'Other',
        ];
    }
}
