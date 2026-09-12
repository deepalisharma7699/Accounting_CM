<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\PartyRole;
use App\Enums\PaymentStatus;
use App\Enums\TenantStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UserStatus;
use App\Models\ChartOfAccount;
use App\Models\Item;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Modules;
use App\Support\Tenancy\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Testing\TestView;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Smoke tests for the Blade shells: they must compile and contain the design's
 * structure. Data itself is hydrated client-side, so there is nothing else to
 * assert server-side.
 *
 * ## What changed with the level-1 workspace
 *
 * There used to be a page per module. There is one page now — `/dashboard` — and
 * modules are cards on it that open in the mounted shell (CLAUDE.md §1.3–§1.5).
 * So the assertions come in three kinds:
 *
 * - **The shell**, over HTTP: the card grid, the topbar, what a module's
 *   fragment serves at `/modules/{key}`, and where the old paths redirect to.
 * - **A module's markup**, rendered directly with `$this->view()`. A module that
 *   is switched off in config/modules.php serves no fragment, but its markup is
 *   still shipped and still has to be right — deleting these assertions because
 *   a card is hidden would be losing the coverage rather than moving it.
 *
 * There is no page shell left but the dashboard. The counter at `/bills/new` was
 * the last one, and C4 retired it when the Jobs card took over raising a
 * workshop bill.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | The way in
     | ------------------------------------------------------------------ */

    public function test_the_public_page_renders_the_shop_and_carries_the_sign_in_form(): void
    {
        $response = $this->get('/')->assertOk();

        // What a visitor came for: the trade, and how to reach the shop.
        // Deeper coverage of the site itself is in tests/Feature/Site.
        $response->assertSee('Motor rewinding', escape: false)
            ->assertSee('Submersible &amp; openwell pumps', escape: false)
            ->assertSee('Visit the shop', escape: false)
            ->assertSee('data-page="site"', escape: false);

        // And the way in, in a modal on the same page rather than on a screen
        // of its own. The ids are what initLogin() binds to, so they are
        // asserted rather than left to the markup.
        $response->assertSee('id="login-modal"', escape: false)
            ->assertSee('data-login-open', escape: false)
            ->assertSee('Welcome back', escape: false)
            ->assertSee('Sign in to your AI Accounting Back Office', escape: false)
            ->assertSee('id="login-form"', escape: false)
            ->assertSee('name="email"', escape: false)
            ->assertSee('name="password"', escape: false);
    }

    public function test_the_login_url_lands_on_the_public_page_with_the_form_open(): void
    {
        /*
        | /login is where the whole application sends somebody whose session has
        | ended — app.js on a failed bootstrap, and initLogout() after signing
        | out. It must still lead to a form, and to one that is already open:
        | landing on a marketing page and having to find the button would be a
        | worse ending to a session than the one it replaces.
        */
        $this->get('/login')->assertRedirect('/?login=1');
    }

    public function test_the_root_path_is_the_public_page_rather_than_the_dashboard(): void
    {
        // It used to redirect. The site is what lives at the root now, and the
        // dashboard is behind the sign-in modal on it.
        $this->get('/')
            ->assertOk()
            ->assertSee('Choudhary Motors', escape: false);
    }

    public function test_the_register_page_renders_the_workshop_and_owner_fields(): void
    {
        config()->set('tenancy.allow_public_signup', true);

        $response = $this->get('/register')->assertOk();

        // Sign-up provisions a workshop and its owner together, so the form
        // must collect both halves.
        foreach (['workshop_name', 'gstin', 'name', 'email', 'password', 'password_confirmation'] as $field) {
            $response->assertSee('name="'.$field.'"', escape: false);
        }

        $response->assertSee('id="register-form"', escape: false)
            ->assertSee('Create your workshop', escape: false);
    }

    public function test_the_register_page_does_not_exist_when_public_signup_is_disabled(): void
    {
        config()->set('tenancy.allow_public_signup', false);

        // 404, not a rendered form: a visible page whose endpoint answers 403
        // is worse than no page at all.
        $this->get('/register')->assertNotFound();
    }

    public function test_the_sign_in_modal_only_offers_sign_up_when_it_is_enabled(): void
    {
        // Onboarding is sales-led, so the shipped default offers no sign-up
        // link at all — the modal signs people in and does nothing else.
        $this->get('/')->assertOk()->assertDontSee('Create your workshop', escape: false);

        config()->set('tenancy.allow_public_signup', true);

        $this->get('/')->assertOk()->assertSee('Create your workshop', escape: false);
    }

    /* ---------------------------------------------------------------------
     | The shell — one page for the whole application
     | ------------------------------------------------------------------ */

    /**
     * The dashboard's two regions — §1.3.
     *
     * `#view-home` is the module grid; `#view-module` is where a card opens.
     * Exactly one is on screen, and the swap between them is the only thing that
     * ever changes below the topbar.
     */
    public function test_the_dashboard_renders_the_shell(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('id="view-home"', escape: false)
            ->assertSee('id="view-module"', escape: false)
            ->assertSee('data-page="dashboard"', escape: false)
            ->assertSee('data-module-card', escape: false);
    }

    /**
     * Home is the module grid and nothing else.
     *
     * It used to carry the day's figures as well. They are gone on purpose:
     * home is the way in to the work (§1.3, §1.4), and a screen that is also a
     * report gives somebody a wall to read before they can reach the module they
     * opened the tab for. Each figure belongs beside the records it summarises.
     *
     * Named individually so this fails loudly if one is ever reintroduced,
     * rather than merely going quiet.
     */
    public function test_the_home_page_carries_nothing_but_the_module_cards(): void
    {
        $content = $this->get('/dashboard')->assertOk()->getContent();

        $mounts = [
            'data-today-tiles', 'data-attention', 'data-attention-count',
            'data-job-tiles', 'data-count-tiles', 'data-activity',
            'data-quick-action', 'data-user-firstname',
        ];

        foreach ($mounts as $mount) {
            $this->assertStringNotContainsString($mount, $content);
        }

        foreach (['Quick Actions', 'Today', 'Needs Your Attention', 'On the bench',
            'Recent Activity', 'Your workshop', 'Good Morning', 'Good Afternoon', 'Good Evening'] as $section) {
            $this->assertStringNotContainsString($section, $content);
        }

        // The bell went with them: no handler, no feed, and a permanent unread
        // dot over an empty list is a control that teaches people to ignore it.
        $this->assertStringNotContainsString('aria-label="Notifications"', $content);
    }

    /**
     * A platform super-admin holds every grant but belongs to no workshop, so
     * every workshop-scoped card is stripped and the grid comes out empty. The
     * page has to say so — a blank screen reads as a broken one.
     */
    public function test_the_home_page_has_something_to_say_when_no_card_survives(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-no-modules', escape: false)
            ->assertSee('administers the platform', escape: false);
    }

    /**
     * §1.4 — the modules are cards, and every card opens one.
     *
     * This replaced "Quick Actions", which was four hand-picked links to four
     * page routes. A card is the same idea done once and done for every module
     * that exists, and it opens the module rather than pointing at it.
     */
    public function test_the_dashboard_offers_a_card_for_every_enabled_module(): void
    {
        $content = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(
            count(Modules::all()),
            substr_count($content, 'data-module-card'),
            'The grid has one card per enabled module — no more, and none missing.',
        );

        foreach (Modules::all() as $key => $module) {
            $this->assertStringContainsString('data-open="'.$key.'"', $content);
            $this->assertStringContainsString($module['label'], $content);
        }
    }

    /**
     * The favourites row is declared empty and holds no card of its own.
     *
     * The whole of it is the point: the section is markup with nothing in it, and
     * resources/js/favourites.js *moves* card nodes into it. The assertion that
     * matters is the one above — exactly one card per enabled module — and it is
     * what a second copy rendered here would break. So this checks the host is
     * present and that nothing has quietly started rendering cards into it.
     *
     * Nothing user-specific is in here either. Which cards are starred belongs to
     * the workshop and arrives in /auth/me; this shell is public, and a workshop's
     * arrangement baked into it would be one anybody could fetch.
     */
    public function test_the_dashboard_declares_an_empty_favourites_row(): void
    {
        $content = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('data-favourites', $content);
        $this->assertStringContainsString('data-favourites-grid', $content);

        // One star per card, gated on the grant that may rearrange the screen.
        $this->assertSame(
            count(Modules::all()),
            substr_count($content, 'data-star='),
            'Every card carries exactly one star — no more, and none missing.',
        );

        // The grid is declared and closed with nothing between the two.
        $this->assertMatchesRegularExpression(
            '/data-favourites-grid><\/div>/',
            preg_replace('/\s+/', '', $content),
            'The favourites grid is populated by moving cards into it, never by rendering them twice.',
        );
    }

    /**
     * Home opens on a skeleton, and the skeleton is in the document.
     *
     * Every card starts hidden and is revealed only once /auth/me confirms the
     * grant behind it, so for one round trip home is band headings over nothing.
     * The fix only works if the loading state is the markup's own: anything
     * JavaScript switches on arrives after the first paint, which is the paint
     * it exists to prevent.
     */
    public function test_home_is_delivered_in_its_loading_state(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@id="view-home"][@data-home-loading][@aria-busy="true"]')->length,
            'Home must arrive already in its loading state — shell.js takes it off, and never puts it on.',
        );

        $this->assertSame(
            1,
            $xpath->query('//*[@id="view-home"]/*[@data-home-skeleton]')->length,
            'The skeleton is a child of #view-home — the CSS that hides its siblings while loading depends on it.',
        );
    }

    /**
     * And the skeleton is not made of cards.
     *
     * Three separate things read the grid off `[data-module-card]` — shell.js's
     * label registry, `permitted()`, and favourites.js recording each band's
     * order — and every one of them would take a placeholder for a module. This
     * is what keeps the count in
     * {@see self::test_the_dashboard_offers_a_card_for_every_enabled_module()}
     * honest as well.
     */
    public function test_the_home_skeleton_declares_no_cards_of_its_own(): void
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML((string) $this->get('/dashboard')->assertOk()->getContent());
        libxml_clear_errors();
        $xpath = new DOMXPath($document);

        foreach (['data-module-card', 'data-open', 'data-star', 'data-module-group'] as $attribute) {
            $this->assertSame(
                0,
                $xpath->query('//*[@data-home-skeleton]//*[@'.$attribute.']')->length,
                'The home skeleton must carry no '.$attribute.' — it is a shape, not a module.',
            );
        }
    }

    /**
     * A module switched off in config/modules.php is off, not merely unlisted.
     *
     * No card, and no fragment either — a URL somebody kept must not be a way
     * round the switch.
     */
    public function test_a_disabled_module_has_no_card_and_serves_no_fragment(): void
    {
        $disabled = array_diff(array_keys(Modules::declared()), array_keys(Modules::all()));

        if ($disabled === []) {
            $this->markTestSkipped('Every module is enabled.');
        }

        $content = $this->get('/dashboard')->assertOk()->getContent();

        foreach ($disabled as $key) {
            $this->assertStringNotContainsString('data-open="'.$key.'"', $content);
            $this->get('/modules/'.$key)->assertNotFound();
        }
    }

    public function test_the_fragment_route_only_serves_modules_from_the_registry(): void
    {
        // `{module}` comes from the URL, so it is checked against the registry
        // rather than interpolated into a view name.
        $this->get('/modules/nope')->assertNotFound();
        $this->get('/modules/layouts')->assertNotFound();
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function declaredModules(): array
    {
        return array_map(fn (string $key) => [$key], array_keys(config('modules', [])));
    }

    #[DataProvider('declaredModules')]
    public function test_an_old_module_path_redirects_into_the_shell(string $key): void
    {
        // Every module used to be a page. They are cards now, so the old paths
        // are redirects — kept, and kept named, because the names are what the
        // rest of the application links by.
        $this->get('/'.$key)->assertRedirect('/dashboard#'.$key);
    }

    public function test_the_old_parties_url_still_lands_somewhere(): void
    {
        // The screen Customers and Vendors replaced. It redirects one step
        // further along the same chain rather than being deleted.
        $this->get('/parties')->assertRedirect('/dashboard#customers');
    }

    public function test_the_dashboard_bakes_in_no_figures_of_its_own(): void
    {
        $content = $this->get('/dashboard')->assertOk()->getContent();

        // The placeholders, named individually so this fails loudly if one is
        // ever reintroduced rather than merely going quiet.
        foreach (['₹14,500', 'Voice Transaction', 'Low Stock Items', 'Daily backup completed'] as $invented) {
            $this->assertStringNotContainsString(
                $invented,
                $content,
                'The dashboard shell must carry no figures of its own — every one is a guarded read.',
            );
        }
    }

    /**
     * The print copy of an invoice — M20.
     *
     * A direct child of `body`, because the print rule in app.css keeps whichever
     * child of `body` contains the document and hides every other one. Nested one
     * level deeper it would be hidden along with whatever it was nested inside,
     * and Print would produce a blank sheet — which nothing on the screen would
     * show. The customer's copy is the same claim from the other side; it is
     * asserted in InvoiceShareTest.
     */
    public function test_the_shell_mounts_the_invoice_print_sheet_as_a_child_of_body(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('id="invoice-print"', $html);
        // The same partial the customer's page includes — one document, two
        // copies, and no second piece of markup to drift.
        $this->assertStringContainsString('data-invoice-document', $html);

        // Parsed rather than pattern-matched, because the claim is structural:
        // the sheet is a *child of body*, not merely present in the markup.
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $this->assertSame(
            1,
            (new DOMXPath($document))->query('/html/body/*[@id="invoice-print"]')->length,
            'The invoice print sheet must be a direct child of <body> — see the print rule in app.css.',
        );
    }

    /**
     * There is one invoice sheet in the shell, and the preview borrows it.
     *
     * `components/invoice-delivery.js` moves that single node into
     * `#invoice-preview` while
     * somebody reads a freshly posted invoice, and hands it back before anything
     * prints. The print rule keeps whichever child of `body` *contains* the
     * document and hides every other one, so a second `[data-invoice-document]`
     * rendered anywhere under `<main>` would make `<main>` worth keeping — and
     * every print from then on would carry the whole application around the
     * invoice, which nothing on the screen would show.
     *
     * So what is asserted is the precondition the moving depends on: the shell
     * renders exactly one sheet, in one child of `body`, and the drawer that
     * borrows it is a child of `body` too — nested inside a module it would be
     * detached with that module, taking the document off the page with it.
     */
    public function test_the_shell_renders_one_invoice_sheet_and_a_body_level_preview_to_lend_it_to(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('//*[@data-invoice-document]')->length,
            'The shell must render exactly one invoice sheet — see the print rule in app.css.',
        );

        $this->assertSame(
            1,
            $xpath->query('/html/body/*[descendant-or-self::*[@data-invoice-document]]')->length,
            'Exactly one child of <body> must hold the invoice — see the print rule in app.css.',
        );

        $this->assertSame(
            1,
            $xpath->query('/html/body/*[@id="invoice-preview"]')->length,
            'The invoice preview must be a direct child of <body>, or the shell detaches it with its module.',
        );

        // It borrows the sheet; it never renders one. A copy here would be a
        // second document, which is the failure the whole arrangement avoids.
        $this->assertSame(
            0,
            $xpath->query('//*[@id="invoice-preview"]//*[@data-invoice-document]')->length,
            'The preview must render no invoice markup of its own.',
        );
    }

    public function test_the_dashboard_shell_exposes_no_user_data_to_anonymous_visitors(): void
    {
        // The shell is public; every figure behind it comes from the JWT-guarded
        // API. Nothing user-specific may be baked into the HTML.
        $user = User::factory()->create(['name' => 'Harshita Sharma']);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee($user->name, escape: false)
            ->assertDontSee($user->email, escape: false);
    }

    /* ---------------------------------------------------------------------
     | The chrome
     | ------------------------------------------------------------------ */

    public function test_the_topbar_carries_the_breadcrumb_rather_than_a_sidebar(): void
    {
        $response = $this->get('/dashboard')->assertOk();

        // §1.2 — there is no sidebar, and nothing may reintroduce one.
        $response->assertDontSee('data-sidebar', escape: false);

        // The breadcrumb has two faces and shows one: the workshop at level 0,
        // "Home › <module>" at level 1.
        $response->assertSee('data-crumb-back', escape: false)
            ->assertSee('data-crumb-home', escape: false)
            ->assertSee('data-crumb-workspace', escape: false)
            ->assertSee('data-crumb-module', escape: false);
    }

    public function test_the_chrome_does_not_hardcode_a_workshop_name(): void
    {
        // The workshop is painted client-side from /auth/me. A hardcoded name
        // made it impossible to tell which workshop — or whether any — the
        // session belonged to.
        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('XYZ Workshop', escape: false)
            ->assertSee('data-workspace-name', escape: false);
    }

    public function test_level_three_is_mounted_once_for_the_whole_application(): void
    {
        // The confirm dialog used to be included by every screen that needed
        // one, which put a second `#confirm-modal` in the document the moment
        // two of those screens were open — and with modules mounted into one
        // shell, they always are. `confirmAction()` resolves one id.
        $content = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'id="confirm-modal"'));
        $this->assertStringContainsString('id="toast-host"', $content);
    }

    /**
     * The global activity bar — one, in the layout, above everything.
     *
     * It reports every request `auth.call()` makes, so it has to outlive the
     * surface that made one: a drawer fetching its record, and the confirm over
     * that drawer waiting on a delete, are both requests somebody is watching
     * for, and neither could carry an indicator that survives being closed.
     *
     * A body child for that reason, and asserted structurally rather than by
     * pattern, exactly as the invoice sheet above is. Declared inside `main` it
     * would scroll away with the module under it, and inside a module it would
     * be detached with that module by the shell's cache.
     */
    public function test_the_shell_mounts_one_global_activity_bar_as_a_child_of_body(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        // One. Two would be two counters, and whichever finished first would
        // take its own bar down while the other was still working.
        $this->assertSame(1, substr_count($html, 'id="global-loader"'));

        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('/html/body/*[@id="global-loader"]')->length,
            'The activity bar must be a direct child of <body> — it has to outrank every drawer and modal.',
        );

        // Idle on arrival, and `hidden` rather than merely transparent: a
        // progressbar permanently in the accessibility tree reading nothing is
        // announced to a screen reader on every page it lands on.
        $this->assertSame(
            1,
            $xpath->query('/html/body/*[@id="global-loader"][@hidden]')->length,
            'The activity bar must start hidden — nothing is loading when the document arrives.',
        );
    }

    public function test_the_one_page_shell_carries_a_sign_out_control(): void
    {
        // One shell is left. Sign-out moved from the sidebar footer into the
        // topbar's account menu; the handler is delegated from the document, so
        // the only thing the markup has to guarantee is the hook and an
        // accessible name.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-logout', escape: false)
            ->assertSee('aria-label="Sign out"', escape: false);
    }

    /**
     * The counter is gone, and so is the last page shell.
     *
     * `/bills/new` existed for one reason once Purchase and Sales had taken over
     * its ordinary work: it was the only screen that could raise a workshop
     * bill. C4 moved that onto the Jobs card. The path now falls through to the
     * catch-all, which is a 404 rather than a screen — and `route('bills.create')`
     * no longer exists, which is what stops anything linking to it.
     */
    public function test_the_bill_counter_is_retired(): void
    {
        $this->assertFalse(app('router')->has('bills.create'));

        $this->get('/bills/new')->assertNotFound();

        // `/bills` itself is still the Expenses module's redirect.
        $this->get('/bills')->assertRedirect('/dashboard#bills');
    }

    /* ---------------------------------------------------------------------
     | Items — the module converted to the §2A flow
     | ------------------------------------------------------------------ */

    /**
     * §2A — the module opens on its create form, with the list behind a switch.
     *
     * The two surfaces are alternatives, never siblings, and the create form is
     * declared once and *moved* between the level-1 slot and the edit dialog —
     * so there is exactly one `#item-form` in the markup, not one per surface.
     */
    public function test_the_items_module_declares_a_form_surface_and_a_list_surface(): void
    {
        $content = $this->get('/modules/items')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);

        // One form, two homes: the level-1 slot and the dialog it is adopted
        // into for an edit.
        $this->assertSame(1, substr_count($content, 'id="item-form"'));
        $this->assertStringContainsString('data-item-form-slot', $content);
        $this->assertStringContainsString('data-item-modal-slot', $content);

        // The chrome that differs between the two, marked so `adoptForm()` can
        // show the right set.
        $this->assertStringContainsString('data-form-chrome="inline"', $content);
        $this->assertStringContainsString('data-form-chrome="modal"', $content);

        // §2A.3 — one switch control, and it is the workspace's. A second "Add
        // item" button on the toolbar is exactly what that rule forbids.
        $this->assertStringNotContainsString('id="new-item"', $content);
    }

    public function test_the_items_module_renders_its_table_and_modals(): void
    {
        $response = $this->get('/modules/items')->assertOk();

        $response->assertSee('id="items-body"', escape: false)
            ->assertSee('id="item-form"', escape: false)
            // Variants open over the list rather than navigating away: they are
            // read while thinking about the family. A tab of the item drawer
            // rather than a modal of their own, so getting to them never costs
            // the row you came from.
            ->assertSee('id="item-drawer"', escape: false)
            ->assertSee('data-tab="variants"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ITEMS"', escape: false);

        /*
        | And there is no second variant form.
        |
        | `#variant-form` was a dialog of its own, and it had already drifted from
        | the block on the create form: it could set a target markup the block
        | could not, and the block could set a barcode, a purchase price and a
        | minimum stock it dropped on the floor. One editor now — the block — with
        | the drawer's pencil opening the item form on that variant (§5.1).
        */
        $response->assertDontSee('id="variant-form"', escape: false)
            ->assertDontSee('id="variant-modal"', escape: false);

        /*
        | The categories and the units are *not* in the markup, and that is the
        | assertion.
        |
        | They are rows an admin edits, so rendering them here would be a copy
        | that goes stale the moment one is added — the exact failure this module
        | was rebuilt to remove. The template ships empty selects and the page
        | module fills them from GET /items/meta.
        */
        $response->assertSee('id="item-type"', escape: false)
            ->assertSee('id="item-uom"', escape: false)
            ->assertDontSee('<option value="motor">', escape: false)
            ->assertDontSee('<option value="piece">', escape: false);

        // The specification section the category's fields are drawn into — one
        // per variant block, so it is a hook rather than an id — and the way to
        // the masters that define them.
        $response->assertSee('data-variant-attributes', escape: false)
            ->assertSee('id="manage-catalogue"', escape: false)
            ->assertSee('id="catalogue-drawer"', escape: false);
    }

    public function test_the_items_form_repeats_its_variant_block_from_a_template(): void
    {
        $response = $this->get('/modules/items')->assertOk();

        /*
        | A product is added with the things on the shelf under it, and there are
        | usually several — a motor family in three ratings. The block is
        | declared once, in a <template>, and cloned per variant.
        |
        | Exactly one of it. Two would be two sets of fields drifting apart,
        | which is the failure §5.1 exists to prevent and the one this module has
        | already had to fix once for the party form.
        */
        $content = $response->getContent();

        $this->assertSame(1, substr_count($content, 'id="item-variant-template"'));

        $response->assertSee('id="item-variants"', escape: false)
            ->assertSee('id="item-add-variant"', escape: false)
            ->assertSee('data-variant-block', escape: false);

        /*
        | Nothing in the template carries an index of its own.
        |
        | pages/items.js stamps the position onto every name, id, `for` and error
        | slot when a block is added or removed, which is what gives a 422 about
        | `variants.2.sell_price` a box to land in. A `name="variants.0.sku"`
        | written here would be a second place the numbering is decided, and the
        | drift shows up as a refusal painted into the wrong block.
        */
        $response->assertDontSee('name="variants.0', escape: false)
            ->assertDontSee('data-error-for="variants.0', escape: false);

        /*
        | Every field a variant has, by the API key each is declared under.
        |
        | The list is the assertion rather than a sample. This block is the only
        | variant editor in the module, so a column the catalogue holds and this
        | does not ask for is a column that can be set once, on a create, and
        | never corrected — which is exactly what `barcode`, `min_stock` and
        | `purchase_price` were while the editor was a dialog of its own.
        */
        foreach ([
            'sku', 'barcode', 'label', 'purchase_price', 'sell_price',
            'markup_percent', 'reorder_level', 'min_stock', 'opening_stock', 'opening_cost',
        ] as $field) {
            $response->assertSee('data-variant-field="'.$field.'"', escape: false);
        }
    }

    public function test_the_items_form_carries_both_variant_panes(): void
    {
        $response = $this->get('/modules/items')->assertOk();

        /*
        | Two panes over one set of fields, and §2A.2's judgement one level down:
        | a product with several variants gets the picker, one with exactly one
        | gets that variant's block straight away, and a create gets the repeater.
        |
        | Both panes are declared here and neither is rendered here — the rows are
        | drawn by pages/items.js from the same renderer the drawer's Variants tab
        | uses, so this asserts the hosts and the controls between them.
        */
        $response->assertSee('id="item-variant-list"', escape: false)
            ->assertSee('id="item-variants"', escape: false)
            ->assertSee('id="item-variant-back"', escape: false);

        /*
        | What an edit withholds, marked once and in one way.
        |
        | Opening stock is a stock adjustment that posted on the day the shelf was
        | counted; correcting it is a count, from the screen that counts. The
        | date and both per-block boxes carry the same hook so that rule is
        | applied in one place rather than by remembering three ids.
        */
        $this->assertSame(3, substr_count($response->getContent(), 'data-opening-field'));

        // And nothing is hidden by the marker that used to hide the whole half:
        // the variant fields are editable now, which is the point of the phase.
        $response->assertDontSee('data-variant-half', escape: false);
    }

    public function test_the_items_module_carries_a_review_queue(): void
    {
        $response = $this->get('/modules/items')->assertOk();

        // The draft queue is surfaced rather than hidden behind a filter: nobody
        // goes looking for a queue they were not told about. The count itself is
        // filled from GET /items/meta, so only the shell is here.
        $response->assertSee('id="draft-banner"', escape: false)
            ->assertSee('id="draft-banner-title"', escape: false)
            ->assertSee('Auto-created from an import or a capture', escape: false);

        /*
        | And a way *out* of the queue, beside the message that puts a record in
        | it. The banner counted drafts from the day it was written and nothing
        | anywhere could clear one, so the only worklist this application has was
        | one that could never reach zero.
        */
        $response->assertSee('id="drawer-clear-draft"', escape: false);

        /*
        | Gated by the page module, never declaratively.
        |
        | `data-requires-permission` works by toggling `hidden`, and so does the
        | condition that this control is only for a family still waiting — two
        | writers of one class, with whichever ran last as the answer. The grant
        | is checked in renderDrawerAlert() alongside the draft flag, and this is
        | what stops the attribute being helpfully added back.
        */
        $this->assertDoesNotMatchRegularExpression(
            '/id="drawer-clear-draft"[^>]*data-requires-permission/s',
            $response->getContent(),
            'The sign-off control must not carry data-requires-permission: that gate and the draft '
            .'condition both write the `hidden` class, and the last one to run would win.',
        );
    }

    public function test_every_stock_bearing_element_on_the_items_module_declares_its_gate(): void
    {
        $response = $this->get('/modules/items')->assertOk();

        // The catalogue is M7's and the quantities are M8's, behind separate
        // grants. Each stock-bearing element carries `data-stock-only` so the
        // page module can remove it outright for a user holding READ:ITEMS
        // without READ:STOCK — blanked cells would read as "none on the shelf"
        // when they mean "not yours to see".
        $response->assertSee('data-stock-only', escape: false);

        // The columns that only mean something once M8 has answered.
        foreach (['Stock', 'Avg Cost', 'Selling Price'] as $column) {
            $response->assertSee('>'.$column.'</th>', escape: false);
        }

        // Each of those three headers is gated, not just some of them: a table
        // that dropped two of three columns would leave a lone "Stock" heading
        // over nothing.
        $this->assertSame(
            3,
            substr_count($response->getContent(), 'data-stock-only" scope="col"')
                + substr_count($response->getContent(), 'scope="col" data-stock-only'),
            'Every stock column header must carry data-stock-only.',
        );
    }

    public function test_the_variant_form_does_not_hardcode_an_attribute_schema(): void
    {
        // The attribute fields are built from GET /items/meta, because which
        // fields a variant has depends on its item's type — and a copy of that
        // mapping in the markup is a copy that drifts. The drift shows up as a
        // motor saved without its rating.
        $response = $this->get('/modules/items')->assertOk();

        // A hook rather than an id: the section is drawn once per variant block,
        // and the create form puts up as many as the workshop is cataloguing.
        $response->assertSee('data-variant-attributes', escape: false)
            ->assertDontSee('data-attribute="hp"', escape: false)
            ->assertDontSee('data-attribute="gauge"', escape: false);
    }

    public function test_the_items_fragment_exposes_no_catalogue_to_anonymous_visitors(): void
    {
        // Every row arrives from the guarded API. A visitor must not learn what a
        // workshop deals in — or what it charges — from the HTML.
        $tenant = Tenant::factory()->create();

        $item = app(TenantContext::class)->runFor(
            $tenant,
            fn () => Item::factory()->motor()->create(['name' => 'Confidential Motor Line'])
        );

        $this->get('/modules/items')
            ->assertOk()
            ->assertDontSee($item->name, escape: false)
            ->assertDontSee($tenant->name, escape: false);
    }

    /* ---------------------------------------------------------------------
     | A module that is switched on is actually reachable
     |
     | A module goes dark by one line in config/modules.php, and flipping that
     | flag is the last step of a conversion and the easiest one to forget.
     | When it is missed there is no card *and* no fragment, and the only
     | symptom anybody sees is "That module is not available" on a screen they
     | were told exists — which reads as the feature never having been built.
     |
     | Vendors was reported that way. Nothing was missing but the flag.
     | ------------------------------------------------------------------ */

    public function test_every_enabled_module_has_a_card_and_a_fragment(): void
    {
        $dashboard = $this->get('/dashboard')->assertOk();

        $this->assertNotEmpty(Modules::all());

        foreach (array_keys(Modules::all()) as $key) {
            $dashboard->assertSee('data-open="'.$key.'"', escape: false);

            $this->get('/modules/'.$key)->assertOk();
        }
    }

    public function test_a_module_that_is_switched_off_has_neither(): void
    {
        $off = array_diff_key(Modules::declared(), Modules::all());

        foreach (array_keys($off) as $key) {
            // The registry is the whitelist the fragment route checks, so an
            // unfinished module cannot be reached by typing its URL either.
            $this->get('/modules/'.$key)->assertNotFound();
        }
    }

    /* ---------------------------------------------------------------------
     | Staff — M22
     | ------------------------------------------------------------------ */

    public function test_the_staff_module_declares_four_sections_each_with_a_form_and_a_list(): void
    {
        $content = $this->get('/modules/staff')->assertOk()->getContent();

        /*
        | Four §2A workspaces under one card, and each is built from the *shared*
        | renderer — `mountWorkspace()` once per section root. So each section
        | carries exactly one `[data-ws-form]` and one `[data-ws-list]`, which is
        | what the workspace looks for and what makes the swap, the switch
        | control and the count badge free.
        */
        foreach (['people', 'attendance', 'payroll', 'advances'] as $section) {
            $this->assertStringContainsString('data-staff-section="'.$section.'"', $content);
            $this->assertStringContainsString('data-staff-tab="'.$section.'"', $content);
        }

        $this->assertSame(4, substr_count($content, 'data-ws-form'));
        $this->assertSame(4, substr_count($content, 'data-ws-list'));

        // No title and no create button of its own: the heading and the one
        // control that swaps the surfaces belong to the workspace (§2A.3).
        $this->assertStringNotContainsString('<h1', $content);
    }

    public function test_the_staff_module_writes_the_employee_form_exactly_once(): void
    {
        $content = $this->get('/modules/staff')->assertOk()->getContent();

        /*
        | One node, two homes. `adoptForm()` moves `#employee-form` between the
        | level-1 slot and the edit drawer; two copies would be two sets of ids,
        | two submit handlers and two places for a pay rule to be added to only
        | one of (§4.4, §5.1).
        */
        $this->assertSame(1, substr_count($content, 'id="employee-form"'));
        $this->assertSame(1, substr_count($content, 'id="employee-name"'));
        $this->assertSame(1, substr_count($content, 'id="employee-rate"'));

        $this->assertStringContainsString('data-employee-form-slot', $content);
        $this->assertStringContainsString('data-employee-modal-slot', $content);

        // The two frames the form is moved between.
        $this->assertStringContainsString('data-form-chrome="modal"', $content);
        $this->assertStringContainsString('data-form-chrome="inline"', $content);
    }

    public function test_the_staff_module_hard_codes_no_designation_no_basis_and_no_attendance_status(): void
    {
        $content = $this->get('/modules/staff')->assertOk()->getContent();

        /*
        | The catalogue module's rule, applied to the staff module's vocabulary.
        |
        | Designations are rows somebody maintains, and the two salary bases and
        | the six attendance states are enums — all of them arrive from
        | GET /api/v1/staff/meta. A copy in this markup would go stale the moment
        | an owner added a designation, which is the exact failure the catalogue
        | was rebuilt to remove.
        |
        | Asserted on the *controls* rather than on the whole document, and the
        | distinction is worth keeping: prose naming a Fitter and a Winder to
        | explain what a designation is is copy, not a list. What must not exist
        | is a select, a chip or a data attribute this markup fills in itself.
        */

        // The salary bases: an empty select, filled from the server.
        $this->assertMatchesRegularExpression(
            '/<select id="employee-basis"[^>]*>\s*<\/select>/',
            $content,
            'The salary bases must arrive from /staff/meta, never be written here.',
        );

        // The designations: a placeholder and nothing else.
        preg_match('/<select id="employee-designation".*?<\/select>/s', $content, $matches);

        $this->assertNotEmpty($matches, 'The designation select is missing.');
        $this->assertSame(
            1,
            substr_count($matches[0], '<option'),
            'The designation select must carry its placeholder and nothing else.',
        );

        // The attendance states: no chip and no status value is written out. The
        // day sheet and the register both paint them from the published list.
        foreach (['half_day', 'week_off', 'paid_leave', 'data-status='] as $token) {
            $this->assertStringNotContainsString($token, $content);
        }
    }

    public function test_every_money_moving_control_on_the_staff_module_declares_its_gate(): void
    {
        $content = $this->get('/modules/staff')->assertOk()->getContent();

        /*
        | Presentation only — every endpoint behind these is guarded server-side
        | as well (§6.1, §6.2) — but a button somebody cannot use is a button
        | that teaches them the product is broken.
        */
        foreach ([
            'WRITE:STAFF',
            'UPDATE:STAFF',
            'DELETE:STAFF',
        ] as $grant) {
            $this->assertStringContainsString('data-requires-permission="'.$grant.'"', $content);
        }
    }

    public function test_the_staff_module_is_declared_and_needs_the_staff_grant(): void
    {
        $declared = Modules::declared();

        $this->assertArrayHasKey('staff', $declared);
        $this->assertTrue($declared['staff']['enabled']);

        /*
        | STAFF, not USERS. Who may sign in and who is on the payroll are
        | different questions: most of a workshop's fitters have never touched
        | the software, and one grant for both would mean that letting somebody
        | add a login also let them read every wage in the building.
        */
        $this->assertSame('READ:STAFF', $declared['staff']['permission']);
        $this->assertTrue($declared['staff']['workspace']);

        $this->get('/staff')->assertRedirect('/dashboard#staff');
    }

    public function test_both_halves_of_the_counterparty_are_first_class_modules(): void
    {
        // Reported as a structural gap: /vendors landed on the dashboard with
        // "That module is not available", and /vendor 404s because it is not a
        // route and never was. Each module answers to its own old URL, which is
        // a redirect into the shell rather than a screen (§1.5).
        $this->assertArrayHasKey('vendors', Modules::all());
        $this->assertArrayHasKey('customers', Modules::all());

        $this->get('/vendors')->assertRedirect('/dashboard#vendors');
        $this->get('/customers')->assertRedirect('/dashboard#customers');

        // The screen they replaced still leads somewhere rather than 404ing.
        $this->get('/parties')->assertRedirect('/dashboard#customers');
    }

    public function test_the_items_card_is_gated_on_the_items_grant(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:ITEMS"', escape: false)
            // Both gates apply independently: a platform super-admin holds every
            // grant but belongs to no workshop, and authority is not membership.
            ->assertSee('data-requires-workspace', escape: false)
            ->assertSee('Items', escape: false);
    }

    /* ---------------------------------------------------------------------
     | The modules that are shipped but not yet switched on
     |
     | Their markup is still in the build and still has to be right, so it is
     | rendered directly rather than fetched. What is gone from these assertions
     | is `data-page`: a fragment has no page shell around it.
     | ------------------------------------------------------------------ */

    /* ---------------------------------------------------------------------
     | Accounting — the chart and the trial balance, merged at C5
     |
     | Converted, so the markup is fetched through the fragment route rather
     | than rendered directly: that asserts the route as well as the markup, and
     | a module whose `enabled` flag was never flipped answers 404 here.
     | ------------------------------------------------------------------ */

    /**
     * §2A — the module opens on its create form, with the books behind a switch.
     */
    public function test_the_accounting_module_opens_on_a_form_with_the_books_behind_a_switch(): void
    {
        $content = $this->get('/modules/accounts')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);

        // The heading and the one switch control belong to the workspace, so
        // the markup carries no title of its own (§2A.3).
        $this->assertStringNotContainsString('<h1', $content);

        $form = $this->surfaceMarkup($content, 'data-ws-form');
        $list = $this->surfaceMarkup($content, 'data-ws-list');

        $this->assertStringContainsString('id="account-form"', $form);
        $this->assertStringNotContainsString('id="account-form"', $list);

        $this->assertStringContainsString('data-chart-groups', $list);
        $this->assertStringNotContainsString('data-chart-groups', $form);

        // Creating an account is what only this module can do, and the control
        // is absent rather than disabled for a caller without the grant.
        $this->assertStringContainsString('data-requires-permission="WRITE:ACCOUNTS"', $form);

        // Type options come from the enum, so the create form cannot drift from
        // the five types the ledger actually supports. They are code and not
        // data: each one decides which side increases the account.
        foreach (AccountType::cases() as $type) {
            $this->assertStringContainsString('value="'.$type->value.'"', $form);
            $this->assertStringContainsString($type->label(), $form);
        }
    }

    /**
     * Two views over one period picker, and the trial balance is one of them.
     *
     * That is the whole of the merge: the screen Ledger used to be is the second
     * view here, reconciliation banner included. Two cards would have needed two
     * period pickers and two renderers of this table (§5.1).
     */
    public function test_the_accounting_list_carries_the_chart_and_the_trial_balance(): void
    {
        $list = $this->surfaceMarkup(
            $this->get('/modules/accounts')->assertOk()->getContent(),
            'data-ws-list',
        );

        $this->assertStringContainsString('data-view="chart"', $list);
        $this->assertStringContainsString('data-view="trial"', $list);
        $this->assertStringContainsString('data-view-panel="chart"', $list);
        $this->assertStringContainsString('data-view-panel="trial"', $list);

        // The single most important figure on the screen: if the two sides
        // differ, everything else on it is suspect.
        $this->assertStringContainsString('data-reconciliation', $list);
        $this->assertStringContainsString('data-trial-body', $list);
        $this->assertStringContainsString('data-trial-foot', $list);

        // One period, shared. Two would be the copy that drifts.
        $this->assertSame(1, substr_count($list, 'data-filter-from'));
        $this->assertSame(1, substr_count($list, 'data-filter-to'));
    }

    /**
     * Two grants, and what the second one gates is *removed* rather than blanked.
     *
     * The card is READ:ACCOUNTS; every figure on it is READ:LEDGER, and neither
     * implies the other. The markup has to carry those marks or `pages/accounts`
     * has nothing to strip for a caller holding the first alone — and a column
     * of dashes reads as "every account is at zero", which is a claim about the
     * books rather than about the reader's permissions.
     */
    public function test_the_accounting_module_marks_every_figure_as_ledger_only(): void
    {
        $content = $this->get('/modules/accounts')->assertOk()->getContent();

        $this->assertStringContainsString('data-ledger-only', $content);

        // The trial balance, the period picker and the switch that reaches the
        // trial balance are all behind that mark: with no figures anywhere,
        // there is one view and nothing for a period to mean.
        $this->assertStringContainsString('data-account-views', $content);
        $this->assertSame(3, substr_count($content, 'data-ledger-only'));
    }

    /**
     * One record over a list is level 2, and correcting it is a state of that
     * surface — never a form stacked over a drawer (§2.2).
     */
    public function test_the_accounting_module_carries_one_drawer_and_one_set_of_fields(): void
    {
        $content = $this->get('/modules/accounts')->assertOk()->getContent();

        $this->assertStringContainsString('id="account-drawer"', $content);
        $this->assertStringContainsString('data-account-edit-slot', $content);

        // `adoptForm()` moves the create form into that slot, so the fields are
        // written once. A second `<form id="account-form">` is the bug this
        // asserts against.
        $this->assertSame(1, substr_count($content, 'id="account-form"'));

        $this->assertStringContainsString('data-form-chrome="inline"', $content);
        $this->assertStringContainsString('data-form-chrome="modal"', $content);

        // The journal-entry list this screen used to carry is gone rather than
        // moved: every row of it is on Insights' Day Book, and a fourth copy
        // would be screens answering one question (§5.1).
        $this->assertStringNotContainsString('data-tab="journal"', $content);
        $this->assertStringNotContainsString('id="journal-drawer"', $content);
        $this->assertStringNotContainsString('READ:TRANSACTIONS', $content);
    }

    /**
     * C5 is the one step that removes a key rather than only flipping a flag.
     *
     * Accounting and Ledger were the same question at two zoom levels. The card
     * is `accounts`; `ledger` is gone from the registry, and its path still
     * lands somewhere — the route name is what the rest of the application links
     * by, and a missing one is a 500 where a redirect is a shrug.
     */
    public function test_the_accounting_card_is_on_and_the_ledger_key_is_gone(): void
    {
        $declared = Modules::declared();

        $this->assertArrayHasKey('accounts', $declared);
        $this->assertTrue($declared['accounts']['enabled']);

        $this->assertSame('READ:ACCOUNTS', $declared['accounts']['permission']);
        $this->assertTrue($declared['accounts']['workspace']);

        $this->assertArrayNotHasKey('ledger', $declared);

        $this->get('/accounts')->assertRedirect('/dashboard#accounts');
        $this->get('/ledger')->assertRedirect('/dashboard#accounts');

        // Off means off: the fragment route is the whitelist, so a key nobody
        // declares is not a way round it either.
        $this->get('/modules/ledger')->assertNotFound();
    }

    /**
     * Two modules, not one screen with a switch: separate cards, separate lists,
     * and a form that writes one role without ever asking which.
     *
     * @return array<string, array{0: string, 1: string, 2: PartyRole}>
     */
    public static function counterpartyModules(): array
    {
        return [
            'customers' => ['customers', 'Add customer', PartyRole::Customer],
            'vendors' => ['vendors', 'Add vendor', PartyRole::Vendor],
        ];
    }

    #[DataProvider('counterpartyModules')]
    public function test_a_counterparty_module_renders_its_table_and_modals(
        string $key,
        string $addLabel,
        PartyRole $role,
    ): void {
        $rendered = $this->counterpartyView($key);

        $rendered->assertSee('id="parties-body"', escape: false)
            // The record form is the one the bill counter opens, included rather
            // than copied — see partials/quick-party-modal.blade.php.
            ->assertSee('id="quick-party-form"', escape: false)
            // One counterparty is read in a drawer over the list, because their
            // history is read while thinking about them. A different drawer from
            // the form above, which is why the ids do not collide.
            ->assertSee('id="party-drawer"', escape: false)
            // The statement opens over the list rather than navigating away.
            ->assertSee('id="party-ledger-modal"', escape: false)
            // Editing is gated in the markup as well as on the endpoint. Creating
            // is not marked here: the only control that opens a create is the
            // workspace's own, which is painted for a caller who holds the grant
            // and left off for one who does not.
            ->assertSee('data-requires-permission="UPDATE:PARTIES"', escape: false)
            ->assertSee($addLabel, escape: false);

        // Where the statement paints the position: receivable and payable side
        // by side, never netted, because for a counterparty who is both the two
        // are settled separately. The figures are the JS's; the mount is the
        // markup's, and losing it would take both sides with it.
        $rendered->assertSee('id="party-ledger-position"', escape: false);

        /*
        | There is no role field, on either shape of the form.
        |
        | Which role a record gets is decided by the module it was written from —
        | Vendors writes a vendor, Customers a customer — and a pair of
        | checkboxes asked somebody adding a supplier to make a modelling
        | decision instead. The counterparty who is both is still one record with
        | one combined ledger; that is offered when a name collides, which is the
        | moment the question means anything. See components/quick-party.js.
        */
        $content = $this->counterpartyMarkup($key);

        $this->assertStringNotContainsString('name="roles"', $content);
        $this->assertStringNotContainsString('id="quick-party-roles"', $content);

        // The wording still says which of the two this module writes, because
        // that is now the only thing that does.
        $this->assertStringContainsString($role->label().' Name', $content);
    }

    /**
     * §2A — the module opens on its create form, with the list behind a switch.
     *
     * The two surfaces are alternatives, never siblings, and the form is
     * declared once and *moved* between the level-1 slot and the edit drawer —
     * so there is exactly one `#quick-party-form` in the markup, not one per
     * surface, and exactly one control that opens a create.
     */
    #[DataProvider('counterpartyModules')]
    public function test_a_counterparty_module_declares_a_form_surface_and_a_list_surface(
        string $key,
        string $addLabel,
        PartyRole $role,
    ): void {
        $content = $this->counterpartyMarkup($key);

        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);

        // One form, two frames: the level-1 slot and the drawer it is adopted
        // into for an edit.
        $this->assertSame(1, substr_count($content, 'id="quick-party-form"'));
        $this->assertStringContainsString('data-party-form-slot', $content);
        $this->assertStringContainsString('data-form-chrome="inline"', $content);
        $this->assertStringContainsString('data-form-chrome="modal"', $content);

        // §2A.3 — one switch control, and it is the workspace's. A second "Add
        // vendor" button on the toolbar is exactly what that rule forbids, and
        // the heading belongs to the workspace too.
        $this->assertStringNotContainsString('id="new-party"', $content);
        $this->assertSame(1, substr_count($content, $addLabel));

        // The two are separate modules over one partial, so each has to carry
        // its own wording rather than the other's.
        $this->assertStringNotContainsString($this->otherCounterparty($addLabel), $content);
    }

    /** "Add customer" for the Vendors module, and the other way round. */
    /**
     * The markup of one level-1 surface, so an assertion can say which side of
     * the form/list split a panel is on.
     *
     * §2A.2 detaches whichever surface is not in use, so a panel on the wrong
     * one is invisible at exactly the moment it matters — which makes the split
     * a decision worth asserting rather than a layout accident.
     */
    private function surfaceMarkup(string $content, string $attribute): string
    {
        $start = strpos($content, '<div '.$attribute);

        $this->assertNotFalse($start, "No [{$attribute}] surface in the markup.");

        $depth = 0;
        $offset = $start;
        $length = strlen($content);

        while ($offset < $length) {
            $open = strpos($content, '<div', $offset);
            $close = strpos($content, '</div>', $offset);

            if ($close === false) {
                break;
            }

            if ($open !== false && $open < $close) {
                $depth++;
                $offset = $open + 4;

                continue;
            }

            $depth--;
            $offset = $close + 6;

            if ($depth === 0) {
                return substr($content, $start, $offset - $start);
            }
        }

        $this->fail("The [{$attribute}] surface is not closed.");
    }

    private function otherCounterparty(string $addLabel): string
    {
        return $addLabel === 'Add vendor' ? 'Add customer' : 'Add vendor';
    }

    /**
     * A converted module is asserted through its fragment route, which covers the
     * route as well as the markup. One still switched off answers 404 there, so
     * its markup is rendered directly until it is on — the coverage moves rather
     * than disappearing.
     */
    private function counterpartyView(string $key): TestView|TestResponse
    {
        return array_key_exists($key, Modules::all())
            ? $this->get('/modules/'.$key)->assertOk()
            : $this->view('modules.'.$key);
    }

    private function counterpartyMarkup(string $key): string
    {
        $rendered = $this->counterpartyView($key);

        return $rendered instanceof TestResponse ? $rendered->getContent() : (string) $rendered;
    }

    /* ---------------------------------------------------------------------
    | Transactions — C3. Converted, so it is fetched through the fragment route
    | rather than rendered directly: that asserts the route as well as the
    | markup, and a module whose `enabled` flag was never flipped answers 404
    | here.
    | ------------------------------------------------------------------ */

    /**
     * Three §2A workspaces under one card, built from the shared renderer.
     *
     * Receipt, Payment and Journal voucher are three write acts a workshop only
     * ever does one after another, so they are one card with three sections —
     * the Staff shape. Each carries exactly one `[data-ws-form]` and one
     * `[data-ws-list]`, which is what `mountWorkspace()` looks for and what makes
     * the swap, the switch control and the count badge free.
     */
    public function test_the_transactions_module_declares_three_sections_each_with_a_form_and_a_list(): void
    {
        $content = $this->get('/modules/journal')->assertOk()->getContent();

        foreach (['receipt', 'payment', 'journal'] as $section) {
            $this->assertStringContainsString('data-txn-section="'.$section.'"', $content);
            $this->assertStringContainsString('data-txn-tab="'.$section.'"', $content);
        }

        $this->assertSame(3, substr_count($content, 'data-ws-form'));
        $this->assertSame(3, substr_count($content, 'data-ws-list'));

        // The heading and the one control that swaps the surfaces belong to the
        // workspace, so the markup must not carry a second one of its own
        // (§2A.3).
        $this->assertStringNotContainsString('<h1', $content);
        $this->assertStringNotContainsString('<h2', $content);

        // Receipt is the section the module lands on, and exactly one tab is
        // selected when it is served.
        $this->assertSame(1, substr_count($content, 'aria-selected="true"'));
    }

    /**
     * The settlement section is written once and rendered twice.
     *
     * Receipt and Payment differ in wording, in the party's role and in the
     * route the JS posts to — and in nothing else, because the server does not
     * differ either: two routes over one `StoreSettlementRequest`. Two
     * near-identical templates is how a rule gets added to one and left off the
     * other, which here would mean a cheque number demanded of a customer and
     * not of a supplier (§5.1).
     */
    public function test_the_settlement_section_is_written_once_and_rendered_twice(): void
    {
        $content = $this->get('/modules/journal')->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'data-settlement-form'));
        $this->assertSame(2, substr_count($content, 'data-party-host'));
        $this->assertSame(2, substr_count($content, 'data-settlement-payments'));

        $this->assertSame(1, substr_count($content, 'id="receipt-form"'));
        $this->assertSame(1, substr_count($content, 'id="payment-form"'));

        // Both settlement forms and the voucher take a date and a note, so each
        // field is written three times over the module and never twice.
        foreach (['date', 'notes'] as $field) {
            $this->assertSame(3, substr_count($content, 'name="'.$field.'"'));
        }

        /*
        | The split is the shared component's, mounted into a host — never a
        | second set of payment rows written out here. The modes and their
        | reference rules arrive from GET /transactions/meta, so the form asks
        | for "Cheque number" without a copy of the mapping to keep in step.
        */
        $this->assertStringNotContainsString('value="cheque"', $content);
        $this->assertStringNotContainsString('data-mode=', $content);

        // Absent rather than blanked for a caller without the grant.
        $this->assertStringContainsString('data-requires-permission="WRITE:TRANSACTIONS"', $content);
    }

    /**
     * The voucher grid, and the chart it is emptied of.
     *
     * The accounts arrive from GET /accounts, never from this template — the
     * catalogue's rule about vocabulary, applied to the chart. An expense head
     * added from Accounting has to appear in this picker without a deployment.
     */
    public function test_the_transactions_module_renders_the_double_entry_grid(): void
    {
        $content = $this->get('/modules/journal')->assertOk()->getContent();

        $this->assertStringContainsString('id="voucher-form"', $content);
        $this->assertStringContainsString('data-voucher-lines', $content);
        $this->assertStringContainsString('data-total-debit', $content);
        $this->assertStringContainsString('data-total-credit', $content);
        $this->assertStringContainsString('data-balance-note', $content);

        // Optional, and genuinely so: a depreciation entry and a correcting
        // journal have no counterparty. It is the shared picker, mounted without
        // a role, rather than a select of every party this workshop has.
        $this->assertStringContainsString('data-voucher-party-host', $content);

        // Not one account, not one optgroup, and not one line of the grid.
        $this->assertStringNotContainsString('<optgroup', $content);
        $this->assertMatchesRegularExpression(
            '/<tbody data-voucher-lines>\s*<\/tbody>/',
            $content,
            'The voucher grid must be drawn from the chart the server publishes.',
        );

        // A drawer rather than a centred modal: what a document settles is read
        // while thinking about the row above it.
        $this->assertStringContainsString('id="txn-drawer"', $content);
    }

    /**
     * The transaction list is gone, and this is the assertion that keeps it
     * gone.
     *
     * It was four tabs over every transaction. Sales lists invoices and credit
     * notes, Purchase lists bills and debit notes, Expenses lists expenses, and
     * Insights' Day Book lists every posted document — a fifth copy here would
     * have been four screens answering one question (§5.1). What each section
     * lists now is its own kind and nothing else, asked for by name so the page
     * count agrees with the rows on it.
     */
    public function test_the_transactions_module_lists_nothing_another_card_already_draws(): void
    {
        $view = $this->get('/modules/journal')->assertOk();

        $view->assertDontSee('id="txn-tabs"', escape: false)
            ->assertDontSee('id="journal-rows"', escape: false)
            ->assertDontSee('id="journal-head"', escape: false)
            ->assertDontSee('id="filter-type"', escape: false)
            ->assertDontSee('data-tab="sales"', escape: false)
            ->assertDontSee('data-tab="purchases"', escape: false)
            ->assertDontSee('data-tab="drafts"', escape: false);

        $content = $view->getContent();

        // No type filter, because no section lists more than one type.
        foreach (TransactionType::cases() as $type) {
            $this->assertStringNotContainsString(
                '<option value="'.$type->value.'"',
                $content,
                'No section offers a choice of transaction type.',
            );
        }

        // And no "save as draft" anywhere: this was the last screen in the
        // product that parked a transaction, and money that has moved is a fact.
        $this->assertStringNotContainsString('id="save-draft"', $content);
        $this->assertStringNotContainsString('id="save-settlement-draft"', $content);
    }

    public function test_the_transactions_module_is_declared_and_needs_the_transactions_grant(): void
    {
        $declared = Modules::declared();

        $this->assertArrayHasKey('journal', $declared);
        $this->assertTrue($declared['journal']['enabled']);

        // The same grant Sales, Purchase and Expenses need, so switching this
        // module on re-seeds nothing.
        $this->assertSame('READ:TRANSACTIONS', $declared['journal']['permission']);
        $this->assertTrue($declared['journal']['workspace']);

        // The key is the module's address, and `journal` is what it has always
        // been — the label says Transactions, which is what a workshop looks for.
        $this->get('/journal')->assertRedirect('/dashboard#journal');
    }

    /*
    | Expenses — C2. Converted, so it is fetched through the fragment route
    | rather than rendered directly: that asserts the route as well as the
    | markup, and a module whose `enabled` flag was never flipped answers 404
    | here.
    */

    /**
     * §2A's two surfaces, and which side of the split each part is on.
     *
     * The form is the create surface the module lands on; the list behind "Show
     * list" is expenses and nothing else. §2A.2 detaches whichever is not in
     * use, so a panel on the wrong one is invisible at exactly the moment it
     * matters.
     */
    public function test_the_expenses_module_declares_a_form_surface_and_a_list_surface(): void
    {
        $content = $this->get('/modules/bills')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);

        // The heading and the one switch control belong to the workspace, so the
        // markup must not carry a second one of its own (§2A.3).
        $this->assertStringNotContainsString('<h2', $content);

        $form = $this->surfaceMarkup($content, 'data-ws-form');
        $list = $this->surfaceMarkup($content, 'data-ws-list');

        $this->assertStringContainsString('id="expense-form"', $form);
        $this->assertStringNotContainsString('id="expense-form"', $list);

        $this->assertStringContainsString('data-expense-body', $list);
        $this->assertStringNotContainsString('data-expense-body', $form);

        /*
        | And nothing pointing at the counter. C2 kept a link to /bills/new on
        | this surface because it was then the only screen that could raise a
        | workshop bill; C4 moved that onto the Jobs card and retired the page,
        | so a link here would be a link to a 404.
        */
        $this->assertStringNotContainsString('data-new-bill', $content);
    }

    /**
     * The whole of what the expense endpoint accepts, and nothing the module
     * stopped being.
     *
     * Every field `StoreExpenseRequest` takes from a person is on the form: an
     * expense entered without its claimable GST is one the workshop cannot
     * reclaim, and one without a split is not an event at all.
     */
    public function test_the_expenses_module_offers_the_whole_expense_and_lists_nothing_else(): void
    {
        $view = $this->get('/modules/bills')->assertOk();

        foreach (['date', 'account_id', 'amount', 'gst_amount', 'notes'] as $field) {
            $view->assertSee('name="'.$field.'"', escape: false);
        }

        // The split is the shared component's, mounted into this host — never a
        // second set of payment rows written out here (§5.1).
        $view->assertSee('data-expense-payments', escape: false)
            ->assertDontSee('id="settlement-rows"', escape: false);

        // Absent rather than blanked for a caller without the grant.
        $view->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        /*
        | The transaction list is gone, and this is the assertion that keeps it
        | gone. Sales lists invoices and credit notes, Purchase lists bills and
        | debit notes, and Insights' Day Book lists every posted document — a
        | fourth copy here would be three screens answering one question, which
        | is the §5.1 mistake this module invites.
        */
        $view->assertDontSee('id="bills-body"', escape: false)
            ->assertDontSee('id="bill-modal"', escape: false)
            ->assertDontSee('id="filter-type"', escape: false)
            ->assertDontSee('id="filter-payment"', escape: false);

        // Nothing but expenses is listed, so there is no kind filter and no
        // payment-status filter: a document that is settled the moment it is
        // written has no payment status to ask about.
        foreach (PaymentStatus::cases() as $status) {
            $view->assertDontSee('value="'.$status->value.'"', escape: false);
        }

        // The lifecycle vocabulary does come from its enum, so the one filter
        // that remains cannot drift from the thing it filters on.
        foreach (TransactionStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    /**
     * Sales — the §2A module for what the workshop sold.
     *
     * Fetched rather than rendered, because the module is switched on: this
     * asserts the fragment route serves it as well as that the markup is right.
     */
    public function test_the_sales_module_renders_the_invoice_form_and_the_list(): void
    {
        $view = $this->get('/modules/sales')->assertOk();

        /*
        | §2A's two surfaces. Exactly one is in the DOM at a time, but both are
        | in the markup — the workspace is what detaches whichever is not in use,
        | which is how a half-typed invoice and the list's filters both survive
        | the trip between them.
        */
        $view->assertSee('data-ws-form', escape: false)
            ->assertSee('data-ws-list', escape: false);

        /*
        | The form is the shared document, included and never copied. Asserting
        | its root plus the mount points is asserting that the include actually
        | ran: if somebody pasted a copy of the fields in here instead, this
        | would still pass — but the quick-add dialogs below would not, and they
        | are the part a copy always forgets.
        */
        $view->assertSee('data-bill-document', escape: false)
            ->assertSee('data-party-host', escape: false)
            ->assertSee('data-item-host', escape: false)
            ->assertSee('data-payments-host', escape: false)
            ->assertSee('data-totals-host', escape: false)
            ->assertSee('id="confirm-bill-modal"', escape: false)
            ->assertSee('id="quick-item-modal"', escape: false)
            ->assertSee('id="quick-party-drawer"', escape: false);

        /*
        | A workshop bill is a sale, but it has to post through the job so the
        | invoice is stamped with it and its parts are marked billed — which is
        | the counter's path, not this module's. Painting the banner here would
        | offer a job picker this module cannot honour.
        */
        $view->assertDontSee('data-job-banner', escape: false);

        /*
        | Correcting a posted invoice — the module's own banner over the shared
        | document, hidden until Correct puts one up. The same markup Purchase
        | carries, driven by the same `components/bill-revision.js`.
        |
        | In this module's markup rather than the partial, because the counter at
        | /bills/new raises new documents and has nothing to correct. It starts
        | hidden: a banner claiming an invoice is being corrected on a blank form
        | would be worse than none.
        */
        $view->assertSee('data-revise-banner', escape: false)
            ->assertSee('data-revise-title', escape: false)
            ->assertSee('data-revise-cancel', escape: false);

        $this->assertStringContainsString(
            'hidden',
            substr($view->getContent(), (int) strpos($view->getContent(), 'data-revise-banner'), 160),
            'The correction banner must ship hidden.',
        );

        /*
        | Level 2 — one document, read without losing the list.
        |
        | The body and the footer are empty in the markup: collecting a payment
        | and taking goods back will be *states of this surface* rather than
        | forms stacked over it, which is what §2.2 asks for instead of a modal
        | on a drawer. So there is deliberately no second dialog here to assert.
        */
        $view->assertSee('id="sales-drawer"', escape: false)
            ->assertSee('data-drawer-body', escape: false)
            ->assertSee('data-drawer-actions', escape: false)
            ->assertSee('data-drawer-alert', escape: false);

        /*
        | The drawer is declared after both level-1 surfaces rather than inside
        | either, so the workspace's swap between the form and the list cannot
        | detach it with one of them. Asserted by position, which is the only
        | part of "is a sibling" a rendered string can actually show.
        */
        $html = $view->getContent();

        $this->assertGreaterThan(
            strpos($html, 'data-sales-body'),
            strpos($html, 'id="sales-drawer"'),
            'The drawer must be declared outside the list surface, or showing the form would detach it.',
        );

        // The list's money columns, which are M16's and are derived on read.
        foreach (['Total', 'Paid', 'Due', 'Status'] as $column) {
            $view->assertSee('>'.$column.'</th>', escape: false);
        }

        // Both status vocabularies come from their enums, so neither filter can
        // drift from the thing it filters on.
        foreach (TransactionStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }

        foreach (PaymentStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    /**
     * The kind filter offers this module's two documents and nothing else.
     *
     * A purchase on a screen headed "Sales" is a surprise, and the list is asked
     * for `types[]` by name rather than filtered after the fact — so an option
     * here that the page module does not send would be a filter that silently
     * returned nothing.
     */
    public function test_the_sales_kind_filter_offers_invoices_and_credit_notes_only(): void
    {
        $view = $this->get('/modules/sales')->assertOk();

        $view->assertSee('value="'.TransactionType::Sale->value.'"', escape: false)
            ->assertSee('value="'.TransactionType::SalesReturn->value.'"', escape: false);

        foreach ([TransactionType::Purchase, TransactionType::PurchaseReturn, TransactionType::Expense] as $kind) {
            $this->assertStringNotContainsString(
                '<option value="'.$kind->value.'"',
                $view->getContent(),
                'The Sales kind filter must not offer '.$kind->value.'.',
            );
        }
    }

    /**
     * Sending the customer their invoice — M20's level-3 dialog, in the shell.
     *
     * It was `#sales-share-modal`, declared in the Sales fragment, for as long as
     * Sales was the only screen that handed a customer a document. Jobs is the
     * second, and the shell caches a module's root **detached** — so a dialog
     * declared inside Sales is not in the page at all while the Jobs card is
     * open. It is a child of `body` now, beside the preview that opens it and the
     * sheet they both borrow, and neither module may declare one of its own.
     */
    public function test_the_shell_carries_one_invoice_share_dialog_above_the_preview(): void
    {
        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $this->assertSame(
            1,
            $xpath->query('/html/body/*[@id="invoice-share-modal"]')->length,
            'The share dialog must be a direct child of <body>, or the shell detaches it with a module.',
        );

        // Level 3 over the preview's level 2, and below the confirmation's 60 —
        // so revoking can put a confirm over this without either disappearing.
        $this->assertMatchesRegularExpression(
            '/id="invoice-share-modal".*?z-index:\s*55/s',
            $html,
        );

        $this->assertMatchesRegularExpression(
            '/id="confirm-modal".*?z-index:\s*60/s',
            $html,
            'The confirmation is level 3 and nothing opens over it (§2.2).',
        );
    }

    /**
     * And no module may keep a second one.
     *
     * The failure a copy causes is not a visible one: two dialogs, one live link,
     * and whichever was bound last answers — so this is asserted rather than left
     * to review. Sales is checked by name because it is where the dialog lived.
     */
    public function test_no_module_fragment_declares_a_share_dialog_of_its_own(): void
    {
        foreach (['sales', 'jobs'] as $module) {
            $html = (string) $this->get('/modules/'.$module)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'data-share-body',
                $html,
                'The '.$module.' fragment must borrow the shared share dialog, never declare one.',
            );
        }
    }

    /**
     * The fragment is public markup, exactly as every other module's is. Nothing
     * about who the workshop sells to may be baked into it (§6.3).
     */
    public function test_the_sales_fragment_exposes_no_customers_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create();

        $customer = app(TenantContext::class)->runFor(
            $tenant,
            fn () => Party::factory()->create([
                'name' => 'Confidential Pumping Works',
                'roles' => [PartyRole::Customer->value],
            ])
        );

        $this->get('/modules/sales')
            ->assertOk()
            ->assertDontSee($customer->name, escape: false)
            ->assertDontSee($tenant->name, escape: false);
    }

    /**
     * Sales is one card, and the registry is the only place that says so.
     */
    public function test_the_sales_module_is_declared_and_needs_the_transactions_grant(): void
    {
        $declared = Modules::declared();

        $this->assertArrayHasKey('sales', $declared);

        // Switched on — the card exists and the fragment serves. The card grid
        // itself is asserted generically against the registry further up.
        $this->assertTrue($declared['sales']['enabled']);

        // The same grant the counter already needs, which is why adding this
        // module re-seeds nothing.
        $this->assertSame('READ:TRANSACTIONS', $declared['sales']['permission']);

        // A workshop's own books, so membership is required as well as the
        // grant — a platform admin holds every permission and owns no sales.
        $this->assertTrue($declared['sales']['workspace']);
    }

    /**
     * Purchase — the §2A module for what the workshop buys in.
     *
     * Fetched rather than rendered, now that the module is switched on: this
     * asserts the fragment route serves it as well as that the markup is right.
     */
    public function test_the_purchase_module_renders_the_document_form_and_the_list(): void
    {
        $view = $this->get('/modules/purchase')->assertOk();

        /*
        | §2A's two surfaces. Exactly one is in the DOM at a time, but both are
        | in the markup — the workspace is what detaches whichever is not in use,
        | which is how a half-typed bill and the list's filters both survive the
        | trip between them.
        */
        $view->assertSee('data-ws-form', escape: false)
            ->assertSee('data-ws-list', escape: false);

        /*
        | The form is the shared document, included and never copied. Asserting
        | its root plus the four mount points is asserting that the include
        | actually ran: if somebody pasted a copy of the fields in here instead,
        | this would still pass — but the quick-add dialogs below would not, and
        | they are the part a copy always forgets.
        */
        $view->assertSee('data-bill-document', escape: false)
            ->assertSee('data-party-host', escape: false)
            ->assertSee('data-item-host', escape: false)
            ->assertSee('data-payments-host', escape: false)
            ->assertSee('data-totals-host', escape: false)
            ->assertSee('id="confirm-bill-modal"', escape: false)
            ->assertSee('id="quick-item-modal"', escape: false)
            ->assertSee('id="quick-party-drawer"', escape: false);

        // A workshop job is billed to a customer, so it has no business on a
        // purchase — and the partial paints no banner for one unless asked.
        $view->assertDontSee('data-job-banner', escape: false);

        /*
        | Correcting a posted bill — the module's own banner over the shared
        | document, hidden until an Edit puts one up.
        |
        | In this module's markup rather than the partial, because the counter at
        | /bills/new raises new documents and has nothing to correct. It starts
        | hidden: a banner claiming a bill is being corrected on a blank form
        | would be worse than none.
        */
        $view->assertSee('data-revise-banner', escape: false)
            ->assertSee('data-revise-title', escape: false)
            ->assertSee('data-revise-cancel', escape: false);

        $this->assertStringContainsString(
            'hidden',
            substr($view->getContent(), (int) strpos($view->getContent(), 'data-revise-banner'), 160),
            'The correction banner must ship hidden.',
        );

        /*
        | Level 2 — one document, read and acted on without losing the list.
        |
        | The body and the footer are empty in the markup: paying and returning
        | are *states of this surface* rather than forms stacked over it, which
        | is what §2.2 asks for instead of a modal on a drawer. So there is
        | deliberately no second dialog here to assert.
        */
        $view->assertSee('id="purchase-drawer"', escape: false)
            ->assertSee('data-drawer-body', escape: false)
            ->assertSee('data-drawer-actions', escape: false)
            ->assertSee('data-drawer-alert', escape: false);

        /*
        | The drawer is declared after both level-1 surfaces rather than inside
        | either, so the workspace's swap between the form and the list cannot
        | detach it with one of them. Asserted by position, which is the only
        | part of "is a sibling" a rendered string can actually show.
        */
        $html = $view->getContent();

        $this->assertGreaterThan(
            strpos($html, 'data-purchase-body'),
            strpos($html, 'id="purchase-drawer"'),
            'The drawer must be declared outside the list surface, or showing the form would detach it.',
        );

        // The list's money columns, which are M16's and are derived on read.
        foreach (['Total', 'Paid', 'Due', 'Status'] as $column) {
            $view->assertSee('>'.$column.'</th>', escape: false);
        }

        // Both status vocabularies come from their enums, so neither filter can
        // drift from the thing it filters on.
        foreach (TransactionStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }

        foreach (PaymentStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    /**
     * The fragment is public markup, exactly as every other module's is. Nothing
     * about who the workshop buys from may be baked into it (§6.3).
     */
    public function test_the_purchase_fragment_exposes_no_suppliers_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create();

        $vendor = app(TenantContext::class)->runFor(
            $tenant,
            fn () => Party::factory()->create([
                'name' => 'Confidential Copper Traders',
                'roles' => [PartyRole::Vendor->value],
            ])
        );

        $this->get('/modules/purchase')
            ->assertOk()
            ->assertDontSee($vendor->name, escape: false)
            ->assertDontSee($tenant->name, escape: false);
    }

    /**
     * Purchase is one card, and the registry is the only place that says so.
     */
    public function test_the_purchase_module_is_declared_and_needs_the_transactions_grant(): void
    {
        $declared = Modules::declared();

        $this->assertArrayHasKey('purchase', $declared);

        // Switched on — the card exists and the fragment serves. The card grid
        // itself is asserted generically against the registry further up.
        $this->assertTrue($declared['purchase']['enabled']);

        // The same grant the counter already needs, which is why adding this
        // module re-seeds nothing.
        $this->assertSame('READ:TRANSACTIONS', $declared['purchase']['permission']);

        // A workshop's own books, so membership is required as well as the
        // grant — a platform admin holds every permission and owns no purchases.
        $this->assertTrue($declared['purchase']['workspace']);
    }

    /* ---------------------------------------------------------------------
     | Jobs — the bench, converted at C4
     | ------------------------------------------------------------------ */

    /**
     * §2A — the module opens on its create form, with the list behind a switch.
     */
    public function test_the_jobs_module_opens_on_a_form_with_its_list_behind_a_switch(): void
    {
        $content = $this->get('/modules/jobs')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);

        // The heading and the one switch control belong to the workspace, so the
        // markup must not carry a title of its own (§2A.3). `<h2` is not the
        // test here, as it is on a module that writes its own form: the shared
        // bill document brings the two quick-add dialogs, and a dialog has a
        // heading.
        $this->assertStringNotContainsString('<h1', $content);

        $form = $this->surfaceMarkup($content, 'data-ws-form');
        $list = $this->surfaceMarkup($content, 'data-ws-list');

        $this->assertStringContainsString('id="job-form"', $form);
        $this->assertStringNotContainsString('id="job-form"', $list);

        $this->assertStringContainsString('data-job-body', $list);
        $this->assertStringNotContainsString('data-job-body', $form);

        // §23's columns.
        foreach (['Job', 'Customer', 'What came in', 'Complaint', 'Status'] as $column) {
            $this->assertStringContainsString('>'.$column.'</th>', $list);
        }

        // Every field describing the thing is optional, and the form has to say
        // so: a pump wheeled in by a driver who does not know its brand still
        // has to be bookable, or the job card gets written on paper.
        $this->assertStringContainsString('whatever is known', $form);
    }

    /**
     * The bench takes in more than motors, and the markup must not name any kind
     * of thing.
     *
     * `hp` and `phase` were two motor fields under a heading that said "The
     * motor", which told the counter it had the wrong screen every time a cooler
     * came in. What is asked now comes from the chosen category's own question
     * set — `GET /workshop-jobs/meta`, drawn by `components/attribute-fields.js`
     * — which is the catalogue's vocabulary rule applied one module along: never
     * a product type in code, and never a list of them rendered into a Blade
     * template, because a copy in the markup goes stale the moment an admin adds
     * one.
     */
    public function test_the_jobs_intake_form_names_no_kind_of_thing(): void
    {
        $form = $this->surfaceMarkup(
            $this->get('/modules/jobs')->assertOk()->getContent(),
            'data-ws-form',
        );

        // The kind, and the host its fields are written into.
        $this->assertStringContainsString('name="category_id"', $form);
        $this->assertStringContainsString('data-job-specs', $form);

        // The two that were columns, and the heading that assumed them.
        $this->assertStringNotContainsString('name="hp"', $form);
        $this->assertStringNotContainsString('name="phase"', $form);
        $this->assertStringNotContainsString('3-phase', $form);

        // Nor any other kind: the only option this file writes is the one that
        // stands for "not answered".
        $this->assertSame(1, substr_count($form, '<option'));
        $this->assertStringContainsString('Not sure yet', $form);
    }

    /**
     * The bill is a level-1 pane on the form surface, not a state of the drawer.
     *
     * A drawer would make the shared document — a searched item picker, a line
     * table, a payment split and a sticky totals panel — into the scroll trap
     * §2.1 refuses. So the create surface holds two panes and shows one, which
     * is §2A.2's judgement applied one level down.
     */
    public function test_the_jobs_module_bills_through_the_shared_document(): void
    {
        $content = $this->get('/modules/jobs')->assertOk()->getContent();

        $form = $this->surfaceMarkup($content, 'data-ws-form');

        $this->assertStringContainsString('data-job-intake', $form);
        $this->assertStringContainsString('data-job-bill', $form);

        // The document itself is the shared partial, never a copy of its fields.
        $this->assertStringContainsString('data-bill-document', $form);

        foreach (['data-party-host', 'data-item-host', 'data-payments-host', 'data-totals-host'] as $hook) {
            $this->assertStringContainsString($hook, $form);
        }

        // §12's confirmation, and the two quick-add dialogs the partial carries.
        $this->assertStringContainsString('id="confirm-bill-modal"', $form);
        $this->assertStringContainsString('id="quick-item-modal"', $form);
        $this->assertStringContainsString('id="quick-party-drawer"', $form);

        // Exactly one of each. Two nodes with one id is what a second copy of
        // the partial would be, and the drawer deliberately carries none.
        $this->assertSame(1, substr_count($content, 'id="quick-item-modal"'));
        $this->assertSame(1, substr_count($content, 'data-bill-document'));
    }

    /**
     * One record over a list is level 2, and correcting it is a state of that
     * surface — never a form stacked over a drawer (§2.2).
     */
    public function test_the_jobs_module_carries_one_drawer_and_one_set_of_fields(): void
    {
        $content = $this->get('/modules/jobs')->assertOk()->getContent();

        $this->assertStringContainsString('id="job-drawer"', $content);
        $this->assertStringContainsString('data-job-edit-slot', $content);

        // `adoptForm()` moves the create form into that slot, so the fields are
        // written once. A second `<form id="job-form">` is the bug this asserts
        // against.
        $this->assertSame(1, substr_count($content, 'id="job-form"'));

        // The customer and the date it arrived are inline-only: neither is
        // editable once the job exists, and `UpdateJobRequest` accepts neither.
        $this->assertStringContainsString('data-form-chrome="inline"', $content);
        $this->assertStringContainsString('data-form-chrome="modal"', $content);
    }

    public function test_the_jobs_module_is_declared_and_needs_the_workshop_grant(): void
    {
        $declared = Modules::declared();

        $this->assertArrayHasKey('jobs', $declared);
        $this->assertTrue($declared['jobs']['enabled']);

        // Gated on WORKSHOP_JOBS rather than on TRANSACTIONS: a job has nothing
        // in the books until somebody bills it, and raising that invoice needs
        // the second grant on top — which the route enforces, not the card.
        $this->assertSame('READ:WORKSHOP_JOBS', $declared['jobs']['permission']);
        $this->assertTrue($declared['jobs']['workspace']);

        $this->get('/jobs')->assertRedirect('/dashboard#jobs');
    }

    /**
     * One form writes to the stock ledger, and both screens that offer it
     * include the same one.
     *
     * Stock counts a shelf and Items corrects one variant, which are the same
     * act entered two ways — the difference, or the count with the difference
     * worked out from the position. A second copy would be a second place the
     * signed quantity, the client reference and the "post: true" are decided,
     * and the sign is the whole meaning of the document (§5.1, §4.3).
     */
    public function test_both_stock_hosts_include_one_adjustment_form(): void
    {
        foreach (['stock', 'items'] as $module) {
            $content = $this->get('/modules/'.$module)->assertOk()->getContent();

            $this->assertStringContainsString('id="stock-adjust-form"', $content, $module);

            // Both modes ship in both hosts. Which one is shown is the calling
            // module's argument, not a second partial.
            $this->assertStringContainsString('data-adjust-mode="count"', $content, $module);
            $this->assertStringContainsString('data-adjust-mode="variant"', $content, $module);

            /*
            | And the error slots are the ones `showFormErrors()` actually
            | paints. This form spent its whole life labelling them `data-error`,
            | which nothing reads — so every 422 it ever took fell through to a
            | toast that named no field and was gone before it was read.
            */
            $this->assertStringNotContainsString('data-error="', $content, $module);
            $this->assertStringContainsString('data-error-for="adjustments"', $content, $module);
        }
    }

    /**
     * §2A.10 — Stock is read-mostly, so it opens on its list.
     *
     * There is no `data-ws-form` in this module and there must not be one: the
     * workspace is mounted with `canCreate: false`, which lands it straight on
     * the table and paints no "Show list" switch. A create form here would be a
     * form for something nobody creates.
     */
    public function test_the_stock_module_opens_on_its_list_and_declares_no_create_form(): void
    {
        $content = $this->get('/modules/stock')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-list', $content);
        $this->assertStringNotContainsString('data-ws-form', $content);
    }

    public function test_the_stock_module_renders_its_table_and_count_form(): void
    {
        $view = $this->view('modules.stock');

        $view->assertSee('id="stock-body"', escape: false)
            // The shared dialog, included rather than written here — see
            // test_both_stock_hosts_include_one_adjustment_form below.
            ->assertSee('id="stock-adjust-form"', escape: false)
            // Level 2 — a drawer rather than a modal, because reading why a
            // figure is what it is is a glance mid-scan and the row you came
            // from should stay visible.
            ->assertSee('id="stock-card-drawer"', escape: false)
            ->assertSee('id="reconciliation"', escape: false)
            // The only control that changes stock, and it posts a transaction —
            // hence WRITE:TRANSACTIONS rather than a stock-specific grant.
            ->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        // Negative stock has a tile of its own. It is a data problem rather than
        // a shortage, and folding it into "low" would train people to ignore it.
        $view->assertSee('id="stat-negative"', escape: false)
            ->assertSee('id="stat-low"', escape: false)
            ->assertSee('id="stat-out"', escape: false);

        /*
        | Each of the three counting tiles is a filter as well as a figure —
        | seeing "6 low" and having no way to ask which six is a dead end. The
        | brief's one-click low-stock alert is this, plus the pill beside it.
        */
        foreach (['low', 'negative', 'out'] as $status) {
            $view->assertSee('data-stat-filter="'.$status.'"', escape: false)
                ->assertSee('data-pill="'.$status.'"', escape: false);
        }

        /*
        | The second level has a pill and deliberately no tile.
        |
        | `min_stock` is the floor where `reorder_level` is the trigger, and a
        | shelf can be under one without being under the other — so it has to be
        | askable. A fifth tile leaves one alone on a row at every breakpoint
        | this grid has, and the state already announces itself in the status
        | column. Asserted so that "add the tile" stays a decision somebody
        | takes rather than one they make by tidying.
        */
        $view->assertSee('data-pill="below_minimum"', escape: false)
            ->assertDontSee('data-stat-filter="below_minimum"', escape: false);

        // The inventory report. Exported client-side from the rows the filters
        // matched, so the file and the table can never disagree.
        $view->assertSee('id="export-csv"', escape: false);

        /*
        | The category filter ships empty and is filled from GET /items/meta,
        | which is also where categories that hold no stock are dropped — labour
        | was never on a shelf, so offering it here would be offering a filter
        | that can only ever come back empty.
        */
        $view->assertSee('id="filter-type"', escape: false)
            ->assertSee('All categories', escape: false)
            ->assertDontSee('<option value="motor">', escape: false);
    }

    /**
     * Insights — M23, and where M12's four statements now live.
     *
     * Fetched through the fragment route rather than rendered with `view()`,
     * which is what the other converted modules do: the module is switched on,
     * so the route is part of what has to keep working. A module still waiting
     * to be converted answers 404 there and is covered with `$this->view()`
     * instead.
     */
    public function test_the_insights_module_renders_its_panels_and_the_statements(): void
    {
        $response = $this->get('/modules/insights');

        $response->assertOk()
            ->assertSee('id="insight-tabs"', escape: false)
            ->assertSee('id="insight-panel"', escape: false)
            ->assertSee('id="filter-period"', escape: false);

        // Six insight tabs and the four statements, on one strip and one period.
        // Two cards would have left somebody guessing which of them had
        // sales-by-month, and would have needed two period pickers.
        foreach (['overview', 'sales', 'purchase', 'stock', 'credit', 'people'] as $panel) {
            $response->assertSee('data-tab="'.$panel.'"', escape: false);
        }

        foreach (['day-book', 'profit-and-loss', 'gst', 'drafts'] as $statement) {
            $response->assertSee('data-tab="'.$statement.'"', escape: false);
        }

        /*
        | The one tab withheld for privacy rather than for authority.
        |
        | STAFF guards what individual people are paid, and the counter clerk who
        | can read the books holds no staff grant. The gate here is presentation
        | — the endpoint requires it too — but a tab that 403s when clicked is
        | worse than one that is not offered.
        */
        $response->assertSee('data-requires-permission="READ:STAFF"', escape: false);

        // The presets come from GET /insights/meta, because "this financial
        // year" depends on the workshop's own year-start setting — a copy in the
        // markup would be right until somebody changed it on the settings
        // screen, and then every bookmark would report the wrong twelve months.
        $response->assertDontSee('value="this_financial_year"', escape: false);

        /*
        | Read-mostly under §2A.10: it opens on its list, so there is no
        | `data-ws-form` at all and the workspace paints no switch control. A
        | form declared here would put a "Create" button on a screen with nothing
        | to create.
        */
        $response->assertSee('data-ws-list', escape: false)
            ->assertDontSee('data-ws-form', escape: false);

        // The trial balance lives on the Ledger module beside the account it
        // drills into. A second copy would be a second thing to keep in step.
        $response->assertDontSee('data-tab="trial-balance"', escape: false);
    }

    /**
     * The Reports card was folded into Insights, and its markup went with it.
     *
     * Asserted rather than assumed, because "one card, one period" is the whole
     * argument for the merge (§5.1) — and a second card quietly reappearing is
     * exactly the drift the rule exists to prevent.
     */
    public function test_the_reports_module_no_longer_exists_separately(): void
    {
        $this->assertArrayNotHasKey('reports', Modules::declared());

        $this->get('/modules/reports')->assertNotFound();
    }

    /**
     * §2A.10 — History is read-mostly, so it opens on its list.
     *
     * The strictest case of it in the product: `canCreate: false` here is not a
     * permission decision that could be widened later, because there is no POST,
     * PATCH or DELETE anywhere in this module's API group and there cannot be.
     * A `data-ws-form` in this markup would be a form for something nobody can
     * create.
     */
    public function test_the_history_module_opens_on_its_list_and_declares_no_create_form(): void
    {
        $content = $this->get('/modules/audit')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-list', $content);
        $this->assertStringNotContainsString('data-ws-form', $content);
    }

    public function test_the_history_module_renders_its_filters(): void
    {
        // Fetched rather than rendered, now the card is on: this asserts the
        // fragment route as well as the markup, so a flag flipped without its
        // route cannot ship.
        $view = $this->get('/modules/audit')->assertOk();

        $view->assertSee('id="audit-rows"', escape: false)
            ->assertSee('id="filter-resource"', escape: false)
            ->assertSee('id="filter-action"', escape: false)
            ->assertSee('id="filter-actor"', escape: false);

        // The options come from GET /audit-logs/meta. The list of things a
        // workshop can change grows with every module, and a copy in the markup
        // would silently stop offering the newest one.
        $view->assertDontSee('value="party"', escape: false)
            ->assertDontSee('value="archived"', escape: false);

        // The commonest question this screen provokes. A posted transaction
        // cannot be edited or deleted at all, so it has no history to show —
        // and an unexplained absence reads as a missing feature.
        $view->assertSee('cannot be edited or deleted', escape: false);
    }

    public function test_the_uploads_module_separates_what_is_in_flight_from_what_is_stored(): void
    {
        $view = $this->view('modules.uploads');

        // Two lists, deliberately. A file still travelling is not yet one of the
        // workshop's records, and a row that appeared in the library and might
        // then vanish would be worse than one that never claimed to be there.
        $view->assertSee('id="upload-queue"', escape: false)
            ->assertSee('id="upload-rows"', escape: false)
            ->assertSee('id="upload-input"', escape: false);

        // `accept` is filled in from GET /attachments/meta, so the picker offers
        // exactly what the server takes. A list written here would be right
        // until an operator raised a limit, and would then refuse files the API
        // would have accepted.
        $view->assertDontSee('accept="image/jpeg', escape: false);

        $view->assertSee('checked in the background', escape: false);
    }

    /*
    | Settings and Opening balances — C1, the go-live pair. Converted, so both
    | are fetched through the fragment route rather than rendered directly: that
    | asserts the route as well as the markup, and a module whose `enabled` flag
    | was never flipped answers 404 here.
    */

    public function test_the_opening_balances_module_declares_a_form_surface_and_a_list_surface(): void
    {
        $content = $this->get('/modules/opening')->assertOk()->getContent();

        // §2A.1 — the module opens on the declaration, with every import ever
        // run behind the one switch control the workspace paints.
        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);

        // The heading and the switch belong to the workspace, so the markup must
        // not carry a second one of its own.
        $this->assertStringNotContainsString('<h2', $content);

        /*
        | The position travels with the *form*, not with the list. It is what
        | somebody about to declare their whole financial history needs in front
        | of them, and §2A.2 keeps only one surface attached — so which side of
        | the split each panel is on is a decision, not a layout accident.
        */
        $form = $this->surfaceMarkup($content, 'data-ws-form');
        $list = $this->surfaceMarkup($content, 'data-ws-list');

        foreach (['id="reconciliation"', 'id="stat-stake"', 'id="opening-form"', 'id="preview-panel"'] as $onForm) {
            $this->assertStringContainsString($onForm, $form);
            $this->assertStringNotContainsString($onForm, $list);
        }

        $this->assertStringContainsString('id="history-rows"', $list);
        $this->assertStringNotContainsString('id="history-rows"', $form);
    }

    public function test_the_opening_balances_module_renders_its_two_step_flow(): void
    {
        $view = $this->get('/modules/opening')->assertOk();

        $view->assertSee('id="opening-form"', escape: false)
            ->assertSee('id="opening-csv"', escape: false)
            ->assertSee('id="preview-panel"', escape: false)
            ->assertSee('id="preview-rows"', escape: false)
            ->assertSee('id="history-rows"', escape: false)
            ->assertSee('id="reconciliation"', escape: false);

        // Checking and posting are two controls, never one. Committing a
        // workshop's whole financial history must not be something that
        // happened because a flag was left out. The conversion left this
        // untouched: it is the module's whole safety property.
        $view->assertSee('id="preview-opening"', escape: false)
            ->assertSee('id="import-opening"', escape: false)
            ->assertSee('Post these balances', escape: false);

        // Declaring what the workshop was worth at go-live is a setup act, so
        // the panel is gated on UPDATE:WORKSPACE rather than WRITE:TRANSACTIONS
        // — a data-entry user holds the second and not the first.
        $view->assertSee('data-requires-permission="UPDATE:WORKSPACE"', escape: false);

        // The column guide is filled from GET /opening-balances/meta, because a
        // copy of the parser's vocabulary in the markup is a copy that drifts —
        // and the drift shows up as instructions that produce a refused file.
        $view->assertSee('id="column-guide"', escape: false)
            ->assertDontSee('bulk_material', escape: false);
    }

    public function test_the_workspace_module_renders_identity_and_book_settings(): void
    {
        $view = $this->get('/modules/workspace')->assertOk();

        $view->assertSee('id="workspace-form"', escape: false)
            ->assertSee('id="welcome-banner"', escape: false);

        foreach (['name', 'gstin', 'state_code', 'address', 'financial_year_start_month', 'timezone', 'books_start_date'] as $field) {
            $view->assertSee('name="'.$field.'"', escape: false);
        }

        // Currency is displayed, never edited — the tax engine is India-specific.
        $view->assertDontSee('name="currency"', escape: false);
    }

    /**
     * One record, so one surface.
     *
     * There is nothing to create on the settings screen, so it declares only
     * `data-ws-list` and mounts with `canCreate: false`. Declaring a form
     * surface as well would paint a switch control to a second surface that
     * does not exist — and `workspace.js` deliberately grew no single-surface
     * mode for this.
     */
    public function test_the_workspace_module_declares_one_surface_and_no_switch(): void
    {
        $content = $this->get('/modules/workspace')->assertOk()->getContent();

        $this->assertStringContainsString('data-ws-list', $content);
        $this->assertStringNotContainsString('data-ws-form', $content);

        // The heading belongs to the workspace.
        $this->assertStringNotContainsString('<h2', $content);
    }

    /**
     * The three settings the API has always accepted and no screen offered.
     *
     * Re-flowing the seven fields that existed and leaving these behind would
     * have made this the module that looks converted and is not: each one
     * changes what the application refuses or how it reports, and until this
     * section there was no way to set any of them.
     */
    public function test_the_workspace_module_offers_the_rules_the_api_accepts(): void
    {
        $view = $this->get('/modules/workspace')->assertOk();

        foreach (['payment_due_days', 'allow_negative_stock', 'round_off_invoices'] as $setting) {
            $view->assertSee('name="'.$setting.'"', escape: false);
        }

        // Each says what it does beside the control, because none of them is
        // guessable from its name.
        $view->assertSee('reported overdue', escape: false)
            ->assertSee('below zero is refused', escape: false)
            ->assertSee('the total a customer pays', escape: false);

        // Absent rather than blanked for a reader: a disabled Save asks somebody
        // to work out for themselves why it will not press.
        $view->assertSee('data-requires-permission="UPDATE:WORKSPACE"', escape: false);
    }

    /*
    | Users and Roles — converted, so both are fetched through the fragment route
    | rather than rendered directly. That asserts the route as well as the
    | markup: a module whose `enabled` flag was never flipped answers 404 here,
    | which is the failure these two spent a release looking like a missing
    | feature.
    */

    public function test_the_users_module_declares_a_form_surface_and_a_list_surface(): void
    {
        $content = $this->get('/modules/users')->assertOk()->getContent();

        // §2A: the module opens on its create form, with the directory behind
        // one switch control the workspace paints.
        $this->assertStringContainsString('data-ws-form', $content);
        $this->assertStringContainsString('data-ws-list', $content);
        $this->assertStringContainsString('data-user-form-slot', $content);

        // One form, two frames — the level-1 slot and the edit dialog. Both sets
        // of chrome are declared on the one node; neither is a second copy of
        // the fields.
        $this->assertSame(1, substr_count($content, 'id="user-form"'));
        $this->assertStringContainsString('data-form-chrome="modal"', $content);
        $this->assertStringContainsString('data-form-chrome="inline"', $content);

        // The heading, the subtitles and the create control belong to the
        // workspace, so the markup must not carry a second one of its own.
        $this->assertStringNotContainsString('id="new-user"', $content);
    }

    public function test_the_users_module_renders_its_directory_and_its_record_form(): void
    {
        $view = $this->get('/modules/users')->assertOk();

        $view->assertSee('id="users-body"', escape: false)
            ->assertSee('id="user-form"', escape: false)
            // One user, read without leaving the directory — level 2.
            ->assertSee('id="user-drawer"', escape: false)
            ->assertSee('id="user-modal"', escape: false)
            // Writing one is gated on WRITE:USERS, reading a role's name on
            // READ:ROLES — the filter is stripped for a caller who holds the
            // first grant without the second.
            ->assertSee('data-requires-permission="WRITE:USERS"', escape: false)
            ->assertSee('data-requires-permission="READ:ROLES"', escape: false);

        // Status options come from the enum, so the form cannot drift from it.
        foreach (UserStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }

        // The roles are rows somebody maintains, published by GET /roles. A copy
        // of them in the markup would go stale the moment one was added.
        $view->assertDontSee('OWNER', escape: false)
            ->assertDontSee('DATA_ENTRY', escape: false);
    }

    public function test_the_roles_module_renders_its_list_and_permission_matrix(): void
    {
        $content = $this->get('/modules/roles')->assertOk()->getContent();

        foreach ([
            'data-ws-form',
            'data-ws-list',
            'data-role-form-slot',
            'id="roles-body"',
            'id="role-form"',
            'id="role-drawer"',
            'id="permission-matrix"',
            'id="role-slug-preview"',
            'data-requires-permission="WRITE:ROLES"',
        ] as $marker) {
            $this->assertStringContainsString($marker, $content);
        }

        // One form, moved between the level-1 slot and the edit dialog.
        $this->assertSame(1, substr_count($content, 'id="role-form"'));
        $this->assertStringNotContainsString('id="new-role"', $content);

        /*
        | The matrix is empty in the markup and filled from
        | GET /permissions?grouped=1. A copy of the catalogue here would be a
        | list of grants that quietly stops matching the ones the middleware
        | checks.
        */
        $this->assertStringNotContainsString('name="permission_ids" value=', $content);
    }

    public function test_the_tenants_module_renders_its_table_and_owner_block(): void
    {
        $view = $this->view('modules.tenants');

        $view->assertSee('id="tenants-body"', escape: false)
            ->assertSee('id="tenant-form"', escape: false)
            ->assertSee('id="tenant-owner-block"', escape: false)
            ->assertSee('data-requires-permission="WRITE:TENANTS"', escape: false);

        // Status options come from the enum, so the filter cannot drift from it.
        foreach (TenantStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    /* ---------------------------------------------------------------------
     | Nothing anywhere leaks a record to a visitor
     | ------------------------------------------------------------------ */

    public function test_no_module_markup_carries_a_workshops_records(): void
    {
        /*
        | Every fragment and every page shell is public; the rows behind them
        | come from the JWT-guarded API. This is the single assertion that used
        | to be repeated per screen — one seeded record of each kind, and no
        | rendered markup may contain any of them.
        */
        $tenant = Tenant::factory()->create(['name' => 'Confidential Motors']);

        [$party, $account, $item] = app(TenantContext::class)->runFor($tenant, fn () => [
            Party::factory()->create(['name' => 'Confidential Winding Co']),
            ChartOfAccount::factory()->ofType(AccountType::Expense)
                ->create(['code' => '5900', 'name' => 'Confidential Retainer']),
            Item::factory()->create(['name' => 'Confidential Bearing']),
        ]);

        $user = User::factory()->create(['name' => 'Harshita Sharma']);
        $role = Role::factory()->create(['name' => 'Secret Role']);

        $secrets = [
            $tenant->name, $tenant->slug, $party->name, $account->name,
            $item->name, $user->email, $role->name,
        ];

        foreach (array_keys(Modules::declared()) as $key) {
            $markup = (string) $this->view("modules.{$key}");

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $markup,
                    "The {$key} module's markup must carry no records of its own.",
                );
            }
        }

        $content = $this->get('/dashboard')->assertOk()->getContent();

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }
}
