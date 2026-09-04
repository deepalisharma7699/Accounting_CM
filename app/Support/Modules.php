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
 *   groups()    the modules that are on, in the order the grid lays them out.
 *   all()       the same, flattened.
 */
final class Modules
{
    /**
     * Every module the file declares, enabled or not, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function declared(): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $groups */
        $groups = config('modules', []);

        return array_merge(...array_values($groups) ?: [[]]);
    }

    /**
     * The enabled modules, flattened, keyed by slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()) ?: [[]]);
    }

    /**
     * The enabled modules as the grid lays them out: the day's work, then
     * administration. A group with nothing left in it is dropped rather than
     * rendered as a heading over an empty row.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function groups(): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $groups */
        $groups = config('modules', []);

        return array_filter(array_map(
            fn (array $modules) => array_filter($modules, fn (array $m) => (bool) ($m['enabled'] ?? true)),
            $groups,
        ));
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
