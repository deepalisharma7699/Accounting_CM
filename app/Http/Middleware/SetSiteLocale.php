<?php

namespace App\Http\Middleware;

use App\Support\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve the public site in the language its URL asks for.
 *
 * The language is in the path, not in a cookie or an Accept-Language header:
 * `/` is English and `/hi` is Hindi, and each route under the Hindi prefix
 * declares `->defaults('locale', 'hi')`. That default is what this reads.
 *
 * Deciding it from the URL rather than from the visitor is the whole point.
 * A cookie-driven switch gives the two languages one address between them, so
 * only one of them can be linked to, shared on WhatsApp or indexed — and for a
 * shop whose customers search in Devanagari, the invisible half would be the
 * one that mattered. It also means the page a visitor shares is the page their
 * cousin opens, rather than whichever language that phone last chose.
 */
class SetSiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route()?->defaults['locale'] ?? Site::DEFAULT_LOCALE;

        // Never take a locale on trust: `locales` in config/shop.php is the
        // whitelist, and anything else falls back rather than reaching
        // app()->setLocale() and being used to build a translation file path.
        if (! isset(config('shop.locales')[$locale])) {
            $locale = Site::DEFAULT_LOCALE;
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
