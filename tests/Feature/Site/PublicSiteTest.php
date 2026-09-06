<?php

namespace Tests\Feature\Site;

use App\Support\Site;
use Tests\TestCase;

/**
 * The public site — `/`, `/hi` and the service pages.
 *
 * PagesRenderTest covers that the root serves the shop and carries the sign-in
 * form. This covers the things about the site that are decisions rather than
 * markup, and each of them is here because getting it wrong is silent:
 *
 *   - a figure nobody confirmed must never be printed as a claim;
 *   - the two languages must stay one site, key for key;
 *   - each language must have its own indexable URL;
 *   - a service without a page must not have a route;
 *   - the sign-in dialog's hooks are an interface with app.js, not styling.
 */
class PublicSiteTest extends TestCase
{
    /* ---------------------------------------------------------------------
     | Both languages
     | ------------------------------------------------------------------ */

    public function test_the_site_is_served_in_english_at_the_root_and_hindi_under_a_prefix(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en-IN"', escape: false)
            ->assertSee('Read the nameplate', escape: false);

        $this->get('/hi')
            ->assertOk()
            ->assertSee('lang="hi-IN"', escape: false)
            // The Hindi page is a translation, not the English page with a
            // switch on it: its own copy has to be in the response.
            ->assertSee('नेमप्लेट', escape: false);
    }

    public function test_each_language_declares_the_other_as_its_translation(): void
    {
        /*
        | Without these the two languages are two URLs carrying the same shop,
        | which is the definition of duplicate content — and the Hindi half,
        | the one this shop's customers actually search in, is the half that
        | would be dropped.
        */
        foreach (['/' => 'http://localhost', '/hi' => 'http://localhost/hi'] as $path => $canonical) {
            $response = $this->get($path)->assertOk();

            $response->assertSee('<link rel="canonical" href="'.$canonical.'">', escape: false)
                ->assertSee('hreflang="en-IN" href="http://localhost"', escape: false)
                ->assertSee('hreflang="hi-IN" href="http://localhost/hi"', escape: false)
                ->assertSee('hreflang="x-default"', escape: false);
        }
    }

    public function test_a_service_page_points_at_its_own_translation_rather_than_the_home_page(): void
    {
        // The bug this guards is a language switch that dumps somebody reading
        // about pump repairs back onto the home page in the other language.
        $this->get('/services/submersible-pumps')
            ->assertOk()
            ->assertSee('hreflang="hi-IN" href="http://localhost/hi/services/submersible-pumps"', escape: false);

        $this->get('/hi/services/submersible-pumps')
            ->assertOk()
            ->assertSee('hreflang="en-IN" href="http://localhost/services/submersible-pumps"', escape: false);
    }

    public function test_every_key_in_one_language_exists_in_the_other(): void
    {
        /*
        | The two language files are one structure written twice. A key added
        | to English and forgotten in Hindi does not fail — Laravel returns the
        | key itself, and the Hindi page quietly renders "site.faq.title" in the
        | middle of a heading. This is the check that turns that into a failure.
        */
        $flatten = function (array $tree, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($tree as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $keys = is_array($value)
                    ? array_merge($keys, $flatten($value, $path))
                    : array_merge($keys, [$path]);
            }

            return $keys;
        };

        $en = $flatten(require lang_path('en/site.php'));
        $hi = $flatten(require lang_path('hi/site.php'));

        $this->assertSame([], array_values(array_diff($en, $hi)), 'Keys in English but missing from Hindi.');
        $this->assertSame([], array_values(array_diff($hi, $en)), 'Keys in Hindi but missing from English.');
    }

    /* ---------------------------------------------------------------------
     | Nothing the shop has not confirmed
     | ------------------------------------------------------------------ */

    public function test_an_unconfirmed_figure_is_dropped_rather_than_printed(): void
    {
        /*
        | The page this replaced carried "32+ years on the same road" and
        | "14k+ motors rewound" as decoration. Nobody had checked either, and a
        | workshop caught inventing its own numbers has nothing left to be
        | believed about — so an unverified fact contributes no tile at all.
        */
        config()->set('shop.facts.motors_rewound', ['value' => 14000, 'verified' => false]);

        $this->assertSame([], Site::stats());
        $this->get('/')->assertOk()->assertDontSee('14,000+', escape: false);
    }

    public function test_a_confirmed_figure_is_printed(): void
    {
        // The other half of the rule: filling the value in and flipping the
        // flag is the whole of publishing it.
        config()->set('shop.facts.warranty_months', ['value' => 6, 'verified' => true]);
        config()->set('shop.facts.established', ['value' => (int) date('Y') - 32, 'verified' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('6 mo', escape: false)
            ->assertSee('32+', escape: false);
    }

    public function test_a_personal_email_is_not_published_as_the_shop_contact(): void
    {
        /*
        | An address published on a public page cannot be withdrawn once it has
        | been scraped, so the e-mail row is absent until somebody confirms a
        | real shop mailbox. The number is the channel these customers use.
        */
        config()->set('shop.email', 'someone@personal.example');
        config()->set('shop.email_verified', false);

        $this->assertNull(Site::email());
        $this->get('/')->assertOk()->assertDontSee('someone@personal.example', escape: false);

        config()->set('shop.email_verified', true);
        $this->get('/')->assertOk()->assertSee('someone@personal.example', escape: false);
    }

    public function test_makes_are_only_named_once_somebody_has_confirmed_them(): void
    {
        // A brand on a shopfront reads as a dealership, which is a different
        // claim and a legal one.
        config()->set('shop.counter.verified', false);
        $this->get('/')->assertOk()->assertDontSee('Kirloskar', escape: false);

        config()->set('shop.counter.verified', true);
        $this->get('/')->assertOk()->assertSee('Kirloskar', escape: false);
    }

    public function test_the_structured_data_carries_no_rating_nobody_left(): void
    {
        /*
        | An `aggregateRating` with no reviews behind it is a fabricated review
        | with a schema wrapper on it, and it is the specific thing search
        | engines penalise a local business for.
        */
        $schema = Site::localBusinessSchema();

        $this->assertSame('LocalBusiness', $schema['@type']);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
        $this->assertArrayNotHasKey('review', $schema);
        $this->assertArrayNotHasKey('priceRange', $schema);
    }

    /* ---------------------------------------------------------------------
     | Structured data, as served
     | ------------------------------------------------------------------ */

    public function test_the_home_page_publishes_the_shop_and_its_questions_as_structured_data(): void
    {
        $content = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        $types = [];

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);

            // A malformed block is worse than none: the whole record is
            // discarded by a crawler and nothing says so.
            $this->assertIsArray($decoded, 'A JSON-LD block on the home page is not valid JSON.');
            $types[] = $decoded['@type'];
        }

        $this->assertSame(['LocalBusiness', 'FAQPage'], $types);
    }

    public function test_a_service_page_publishes_itself_as_a_service(): void
    {
        $content = $this->get('/services/motor-rewinding')->assertOk()->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        $types = array_map(
            static fn (string $json): string => json_decode($json, true)['@type'],
            $matches[1],
        );

        $this->assertSame(['LocalBusiness', 'Service'], $types);
    }

    /* ---------------------------------------------------------------------
     | Service pages
     | ------------------------------------------------------------------ */

    public function test_only_the_services_that_have_copy_have_a_page(): void
    {
        /*
        | config/shop.php decides which services get a page, and the route's
        | whitelist is built from the same list. So a page cannot exist without
        | the long-form copy to fill it, and a service answered fully by its
        | card does not get a page that only repeats the card.
        */
        foreach (Site::pagedServiceSlugs() as $slug) {
            $this->get('/services/'.$slug)->assertOk();
            $this->get('/hi/services/'.$slug)->assertOk();
        }

        foreach (['spares', 'new-motors', 'site-visits'] as $slug) {
            $this->get('/services/'.$slug)->assertNotFound();
        }

        $this->get('/services/nonsense')->assertNotFound();
    }

    public function test_a_service_page_carries_its_own_title_and_description(): void
    {
        // Three pages sharing the home page's title is three pages competing
        // for the same search and none of them answering it.
        $this->get('/services/winding-wire')
            ->assertOk()
            ->assertSee('<title>Copper winding wire — Choudhary Motors, Charkhi Dadri</title>', escape: false)
            ->assertSee('Super-enamelled copper by SWG', escape: false);
    }

    public function test_a_service_page_offers_the_way_back_and_the_other_services(): void
    {
        // Somebody who arrived from a search has never seen the home page.
        $this->get('/services/motor-rewinding')
            ->assertOk()
            ->assertSee('Back to the shop', escape: false)
            ->assertSee('/services/submersible-pumps', escape: false)
            ->assertSee('/services/winding-wire', escape: false);
    }

    /* ---------------------------------------------------------------------
     | The things a visitor came for
     | ------------------------------------------------------------------ */

    public function test_every_page_offers_the_phone_and_a_prefilled_whatsapp_message(): void
    {
        /*
        | The visitor is standing next to a motor that has stopped, holding a
        | phone. Both channels are on every page, and the WhatsApp link opens
        | with the first message already typed because it is composed
        | one-handed.
        */
        foreach (['/', '/hi', '/services/motor-rewinding'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('tel:+919813707087', escape: false)
                ->assertSee('https://wa.me/919813707087?text=', escape: false);
        }
    }

    public function test_the_reference_tables_are_in_the_markup_rather_than_fetched(): void
    {
        /*
        | All three panels ship rendered and the script is what turns them into
        | tabs. A visitor with no JavaScript, and a crawler, get every row —
        | which is the point of putting them on the site at all.
        */
        $this->get('/')
            ->assertOk()
            ->assertSee('0.746', escape: false)      // the HP/kW note
            ->assertSee('1.219', escape: false)      // 18 SWG in millimetres
            ->assertSee('6205', escape: false);      // a bearing everybody asks for
    }

    public function test_the_questions_are_readable_without_javascript(): void
    {
        // <details>, not a script-driven accordion: these answers are also the
        // page's FAQ structured data and have to be in the markup.
        $this->get('/')
            ->assertOk()
            ->assertSee('<details', escape: false)
            ->assertSee('Is it worth rewinding', escape: false);
    }

    /* ---------------------------------------------------------------------
     | The way in
     | ------------------------------------------------------------------ */

    public function test_the_header_offers_the_way_in_on_every_public_page(): void
    {
        /*
        | The regression this exists for: an overhaul of the site left the
        | dialog, the footer button and the /login redirect all working, and
        | dropped the trigger out of the header. Nothing failed — the way in
        | had simply moved to the bottom of a long marketing page, and staff
        | arriving at the shopfront had to scroll past the whole thing to sign
        | in. Asserting `data-login-open` appears somewhere in the response
        | would not have caught it, because the footer still carried one.
        |
        | So this checks the header specifically, on both languages and on a
        | service page as well as the home page — a service page is a real
        | landing spot, and it renders the same partial.
        */
        foreach (['/', '/hi', '/services/motor-rewinding'] as $path) {
            $header = $this->headerOf($path);

            $this->assertStringContainsString('data-login-open', $header,
                "The header on {$path} offers no way to sign in.");
        }
    }

    public function test_the_header_offers_the_way_in_at_every_width(): void
    {
        /*
        | Two triggers, because below `lg` the desktop cluster is hidden behind
        | the hamburger: one in the bar and one in the panel it opens. A single
        | trigger here means whichever width it was not written for has a header
        | with no sign-in — and on this site that width is the phone.
        */
        $header = $this->headerOf('/');

        $this->assertSame(2, substr_count($header, 'data-login-open'),
            'The header needs a sign-in trigger in the bar and one in the mobile panel.');
    }

    /**
     * The markup up to the end of the header, so an assertion about the header
     * cannot be satisfied by the footer's copy of the same hook.
     */
    private function headerOf(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();

        $end = strpos($html, '</header>');

        $this->assertNotFalse($end, "No header rendered on {$path}.");

        return substr($html, 0, $end);
    }

    public function test_the_sign_in_dialog_keeps_the_hooks_app_js_binds_to(): void
    {
        /*
        | These names are an interface with initLogin() in resources/js/app.js,
        | not styling. Renaming one to match the site's `s-` prefix would leave
        | a dialog that opens, accepts a password and does nothing with it.
        */
        $response = $this->get('/hi')->assertOk();

        foreach ([
            'id="login-modal"', 'data-modal', 'data-login-open', 'id="login-form"',
            'id="login-error"', 'data-error-message', 'name="email"', 'name="password"',
            'data-field-error="email"', 'data-field-error="password"',
            'data-toggle-password', 'data-submit', 'data-spinner', 'data-submit-label',
        ] as $hook) {
            $response->assertSee($hook, escape: false);
        }
    }
}
