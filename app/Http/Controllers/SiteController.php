<?php

namespace App\Http\Controllers;

use App\Support\MotorReference;
use App\Support\Site;
use Illuminate\Contracts\View\View;

/**
 * The shopfront — the public site at `/` and `/hi`.
 *
 * Two actions, because the site is two shapes: one long home page, and a
 * deeper page for each of the three services people actually search for by
 * name. Everything either of them renders comes from App\Support\Site, so the
 * controller's job is only to say which page this is and what its search
 * listing should say.
 *
 * There is no authentication anywhere near this class. The sign-in form is a
 * modal on the same page, wired by initLogin() in resources/js/app.js against
 * the JWT endpoints — see routes/web.php for why /login is a redirect here
 * rather than a screen of its own.
 */
class SiteController extends Controller
{
    /** The home page. */
    public function home(): View
    {
        return view('site.home', [
            'meta' => [
                'title' => Site::text('meta.home_title'),
                'description' => Site::text('meta.home_description'),
            ],
            // The canonical route for this page, so the layout can work out
            // what the same page is called in the other language.
            'route' => ['name' => 'home', 'params' => []],
            'services' => Site::services(),
            'stats' => Site::stats(),
            'ratings' => MotorReference::ratings(),
            'gauges' => MotorReference::wireGauges(),
            'bearings' => MotorReference::bearings(),
            'schemas' => [
                Site::localBusinessSchema(),
                Site::faqSchema(),
            ],
        ]);
    }

    /**
     * One service, in depth.
     *
     * The slug is constrained by the route to those config/shop.php marks with
     * `page => true`, so a service that has no page cannot be reached by
     * guessing its slug — it 404s at the router rather than rendering a page
     * with empty sections.
     */
    public function service(string $service): View
    {
        // Belt and braces: the route constraint is built from the same list,
        // but a service switched to `page => false` while a cached route table
        // still names it would otherwise render a shell of a page.
        abort_if(($record = Site::service($service)) === null, 404);

        return view('site.service', [
            'meta' => [
                'title' => Site::text('meta.service_title', ['title' => $record['title']]),
                'description' => $record['summary'],
            ],
            'route' => ['name' => 'services.show', 'params' => ['service' => $service]],
            'service' => $record,
            // The other two, for the footer of the page: somebody reading about
            // pump repairs is one click from the winding wire they will need.
            'others' => array_values(array_filter(
                Site::services(),
                static fn (array $s): bool => $s['has_page'] && $s['slug'] !== $service,
            )),
            'schemas' => [
                Site::localBusinessSchema(),
                Site::serviceSchema($record),
            ],
        ]);
    }
}
