<?php

namespace App\Support;

/**
 * One typed phrase, turned into the LIKE patterns a search should match.
 *
 * ## Why a phrase is not one pattern
 *
 * Every search in this application used to wrap the whole input in `%…%` and
 * test it against each column on its own, which quietly made the one action a
 * user takes to narrow a list the one action that empties it. A workshop holding
 * twenty capacitors under a family called "Capacitor", each variant labelled with
 * its capacitance, typed `capacitor 36` and got **nothing** — `items.name` is
 * "Capacitor" and does not contain "capacitor 36", `item_variants.label` is
 * "36 MFD" and does not either, and no column anywhere holds both words.
 *
 * So the phrase is split, and the two quantifiers are the whole of it: **every
 * word has to match, and a word may match any column.** "capacitor 36" is then
 * (something says capacitor) AND (something says 36), which is what the person
 * typing it meant, and each further word narrows rather than annihilates.
 *
 * ## Why the wildcards are escaped
 *
 * `%` and `_` are LIKE's own operators, so a SKU containing an underscore
 * matched far more loosely than it looked — `MTR_5` found `MTR-5` and `MTR 5`
 * as well. They are escaped with a backslash, which is MySQL's default LIKE
 * escape character, so no `ESCAPE` clause is needed at the call site.
 */
final class SearchTerms
{
    /**
     * Beyond this the words are dropped rather than the search refused.
     *
     * Each word costs a predicate per column, and the joined ones cost a
     * subquery each — five is far past what anybody types at a counter, and a
     * pasted paragraph must not become a hundred-way query (§7.2).
     */
    public const MAX_WORDS = 5;

    /**
     * The patterns a caller must AND together, or `[]` for an empty search.
     *
     * @return array<int, string>
     */
    public static function patterns(?string $term): array
    {
        $words = preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            static fn (string $word) => '%'.self::escape($word).'%',
            array_slice($words, 0, self::MAX_WORDS),
        );
    }

    /**
     * The predicate that reaches a variant's specification.
     *
     * A variant's `label` column is only filled when somebody typed one. The
     * usual case is an empty label and a bag of attributes — `{"capacitance":
     * "200", "type": "Oil filled"}` — with what the screen shows assembled from
     * it in PHP by `ItemVariant::derivedLabel()`. So every row in a capacitor
     * family *displayed* its capacitance and was *searchable* on nothing, and
     * "Capacitor 200" found none of the twenty. Splitting the phrase into words
     * did not help on its own: the second word had nowhere to match.
     *
     * `JSON_SEARCH` over `'$.*'` reaches the bag's **values** and not its keys,
     * which is the distinction that matters — matching keys would make a search
     * for "type" return every product that records one. Both sides are lowered
     * because JSON strings compare case-sensitively, and the pattern keeps the
     * backslash escaping from {@see self::escape()}: `JSON_SEARCH` takes LIKE's
     * own syntax and the same default escape character.
     *
     * Bind the pattern once. It cannot use an index, so it is a scan — the same
     * order of cost as the `%…%` on `label` beside it, and bounded by
     * {@see self::MAX_WORDS}.
     */
    public const ATTRIBUTE_VALUE_MATCH = "json_search(lower(cast(`attributes` as char)), 'one', lower(?), null, '$.*') is not null";

    /**
     * Escape LIKE's operators so a typed one is matched literally.
     *
     * The backslash goes first, or the backslashes this method adds would
     * themselves be escaped by the passes after it.
     */
    private static function escape(string $word): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word);
    }
}
