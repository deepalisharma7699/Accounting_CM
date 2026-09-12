<?php

namespace App\Support;

/**
 * Reads config/modules.php — the registry the shell is built from.
 *
 * A class rather than `config('modules')` calls scattered about, for two
 * reasons. {@see self::exists()} is a security boundary: the fragment route
 * renders a view whose name comes from the URL, and this whitelist is the only
 * thing between that and an arbitrary view render. And `enabled` has to mean the
 * same thing in all three places that ask about a module — the card grid, the
 * fragment route and the shell — or "hidden" would only mean "not linked".
 *
 * The distinction to keep straight:
 *
 *   declared()  every module in the file, on or off. Only the redirects from the
 *               old page routes use this, so that a link to a module that is
 *               currently off still lands somewhere rather than 404ing.
 *   all()       the modules that are on, keyed by slug.
 *   groups()    the same, in the bands the grid lays them out in.
 */
final class Modules
{
    /**
     * The bands of the card grid, in the order they are shown, and what each
     * one is called on screen.
     *
     * Here rather than in config/modules.php because a band is a fact about the
     * *grid* — its order and its heading — where the registry is the list of
     * modules. A module names its band with `group`; this decides what that
     * band is and where it sits.
     *
     * They are five rather than the two this replaced ("Modules" and
     * "Administration"), and they name what a workshop is doing rather than
     * what the software is made of: three or four cards under a heading that
     * says what they are for, instead of twelve under one that does not.
     */
    private const BANDS = [
        'selling' => 'Work & selling',
        'stock' => 'Buying & stock',
        'money' => 'Money & books',
        'people' => 'People',
        'setup' => 'Setup & history',
    ];

    /**
     * Every module the file declares, enabled or not, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function declared(): array
    {
        /** @var array<string, array<string, mixed>> $modules */
        $modules = config('modules', []);

        return $modules;
    }

    /**
     * The enabled modules, keyed by slug, in the order they are declared.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_filter(self::declared(), fn (array $m) => (bool) ($m['enabled'] ?? true));
    }

    /**
     * The enabled modules as the grid lays them out.
     *
     * Keyed by band, in {@see self::BANDS} order — never in the order the
     * modules happen to be declared, which would let adding one reshuffle the
     * screen. Within a band they keep their declared order.
     *
     * A band with nothing left in it is dropped rather than rendered as a
     * heading over an empty row, which is what a workshop running with Uploads
     * switched off would otherwise get.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function groups(): array
    {
        $enabled = self::all();

        $bands = array_map(
            fn (string $band) => array_filter($enabled, fn (array $m) => ($m['group'] ?? null) === $band),
            array_combine(array_keys(self::BANDS), array_keys(self::BANDS)),
        );

        return array_filter($bands);
    }

    /**
     * What the grid calls a band.
     *
     * Falls back to the key rather than to nothing: a module given a band that
     * does not exist yet gets no card at all — {@see self::groups()} — so this
     * is only ever reached for a band that is real and newly added.
     */
    public static function groupLabel(string $group): string
    {
        return self::BANDS[$group] ?? ucfirst($group);
    }

    /**
     * Is this a module the shell may open?
     *
     * The whitelist. `$key` arrives from the URL, so it is checked against the
     * registry rather than interpolated into a view name — otherwise
     * `/modules/../../something` would be a view render of somebody else's
     * choosing. A disabled module answers false here too: switching one off has
     * to close the door as well as remove the sign.
     */
    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
