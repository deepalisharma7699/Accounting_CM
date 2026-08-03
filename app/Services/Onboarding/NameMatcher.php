<?php

namespace App\Services\Onboarding;

/**
 * Matching a name from a spreadsheet against records the workshop already has.
 *
 * **Deterministic, and no language model anywhere near it.** That is M15.3's
 * rule arriving three modules early, and for the same reason: a matcher that
 * sometimes decides "Sharma Motor Winding Co." is "Sharma Motors" is a matcher
 * that will one day post ₹80,000 of somebody else's debt against the wrong
 * party, and nothing downstream can tell. Given the same two strings this
 * returns the same number every time, and that number is inspectable.
 *
 * ## The rungs, in order
 *
 * 1. **Exact**, after normalising case, punctuation and the words that carry no
 *    information — "Sharma Motors Pvt. Ltd." and "SHARMA MOTORS PVT LTD" are the
 *    same business, and treating them as two would split one balance in half,
 *    which is precisely what M5 built one parties table to prevent.
 * 2. **Similar**, by edit distance against the normalised forms, and only above
 *    a threshold high enough that the answer is a typo rather than a guess.
 * 3. **Nothing.** Which is a real answer and usually the right one: a name
 *    nobody recognises is a new record, not a bad match to an old one.
 *
 * There is no "closest of a bad lot". A best-match-wins matcher with no floor
 * will confidently attach an opening balance to whichever of forty parties
 * happens to share the most letters, and be wrong in a way nobody re-reads.
 */
class NameMatcher
{
    /**
     * How close two names must be before this will call them the same thing.
     *
     * Percent of the longer string, so it is not fooled by length: 88 lets
     * through "Sharma Motors" against "Sharma Motor" — a dropped letter — while
     * refusing "Sharma Motors" against "Verma Motors", which differs by two
     * letters and is a different business in the next street.
     *
     * Deliberately strict. The cost of a missed match is a duplicate record
     * somebody merges in a minute; the cost of a wrong match is money on the
     * wrong ledger, found months later or never.
     */
    public const THRESHOLD = 88;

    /**
     * Words that say what kind of business something is and nothing about which
     * one. Stripped before comparing, so a workshop that wrote "Pvt Ltd" on one
     * row and not on another still gets one party.
     *
     * @var array<int, string>
     */
    private const NOISE = [
        'pvt', 'private', 'ltd', 'limited', 'llp', 'inc', 'corp', 'co',
        'company', 'and', 'the', 'm', 's', 'messrs',
    ];

    /**
     * The best match in a set, or null when nothing is close enough.
     *
     * @template T
     *
     * @param  iterable<T>  $candidates
     * @param  callable(T): string  $nameOf
     * @return array{0: T, 1: int}|null The match and how confident, 0–100.
     */
    public function best(string $needle, iterable $candidates, callable $nameOf): ?array
    {
        $target = $this->normalise($needle);

        if ($target === '') {
            return null;
        }

        $bestCandidate = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $score = $this->score($target, $this->normalise($nameOf($candidate)));

            // Exact wins outright, and short-circuits: there is nothing better
            // to find and continuing would only risk a tie being resolved by
            // iteration order.
            if ($score === 100) {
                return [$candidate, 100];
            }

            if ($score > $bestScore) {
                $bestCandidate = $candidate;
                $bestScore = $score;
            }
        }

        return $bestCandidate !== null && $bestScore >= self::THRESHOLD
            ? [$bestCandidate, $bestScore]
            : null;
    }

    /**
     * How alike two already-normalised names are, 0–100.
     */
    public function score(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        $longest = max(strlen($a), strlen($b));

        // levenshtein() is byte-oriented and refuses strings past 255 bytes.
        // Neither matters here — normalise() has already folded the input to
        // ASCII-ish lowercase, and a 255-character item name is not a name.
        if ($longest > 255) {
            return 0;
        }

        $distance = levenshtein($a, $b);

        return (int) round((1 - $distance / $longest) * 100);
    }

    /**
     * A name reduced to what actually identifies it.
     *
     * Lower-cased, punctuation dropped, runs of whitespace collapsed, and the
     * legal-form words removed. Digits are kept and are load-bearing: "6204" and
     * "6205" are two bearings, and any normalisation that lost the difference
     * would be worse than none.
     */
    public function normalise(string $name): string
    {
        $name = strtolower(trim($name));

        // Punctuation to spaces rather than to nothing, so "motors/pumps" does
        // not become one word that matches neither.
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;

        $words = array_values(array_filter(
            explode(' ', $name),
            static fn (string $word) => $word !== '' && ! in_array($word, self::NOISE, true),
        ));

        // Everything was noise — "M/s Pvt Ltd" and nothing else. Falling back to
        // the stripped form is better than returning empty, which would match
        // every other all-noise name.
        if ($words === []) {
            return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        }

        return implode(' ', $words);
    }
}
