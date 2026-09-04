<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\PartyRole;
use App\Enums\PaymentStatus;
use App\Enums\TenantStatus;
use App\Enums\TransactionSource;
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
 * - **The counter** at `/bills/new`, which is still a page of its own.
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
        $response->assertSee('Motor rewinding', escape: false)
            ->assertSee('Submersible pump repairs', escape: false)
            ->assertSee('Visit the shop', escape: false)
            ->assertSee('data-page="welcome"', escape: false);

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
        $this->get('/')->assertOk()->assertSee('Create your workshop', escape: false);

        config()->set('tenancy.allow_public_signup', false);

        $this->get('/')->assertOk()->assertDontSee('Create your workshop', escape: false);
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
        return array_map(fn (string $key) => [$key], array_keys(config('modules.primary', []) + config('modules.admin', [])));
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

    public function test_every_page_shell_carries_a_sign_out_control(): void
    {
        // Two shells are left: the dashboard and the counter. Sign-out moved
        // from the sidebar footer into the topbar's account menu; the handler is
        // delegated from the document, so the only thing the markup has to
        // guarantee is the hook and an accessible name.
        foreach (['/dashboard', '/bills/new'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('data-logout', escape: false)
                ->assertSee('aria-label="Sign out"', escape: false);
        }
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
            ->assertSee('id="variant-form"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ITEMS"', escape: false);

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

        // The specification section the category's fields are drawn into, and
        // the way to the masters that define them.
        $response->assertSee('id="item-attributes"', escape: false)
            ->assertSee('id="manage-catalogue"', escape: false)
            ->assertSee('id="catalogue-drawer"', escape: false);
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

        $response->assertSee('id="variant-attributes"', escape: false)
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
     | The counter — still a page of its own
     | ------------------------------------------------------------------ */

    /**
     * The counter — M20, and the brief's §2, §4, §5, §12 and §26.
     */
    public function test_the_bill_counter_renders_its_pickers_and_its_confirmation(): void
    {
        $response = $this->get('/bills/new')->assertOk();

        $response->assertSee('data-page="bill-counter"', escape: false)
            // The document itself is the shared partial, and this is its root —
            // the element components/bill-document.js scopes every query to.
            // What is left of the counter around it is the kind chooser and the
            // job picker, asserted below.
            ->assertSee('data-bill-document', escape: false)
            // The three mount points. Everything on this screen is a shared
            // component, because the job card and the journal want the same ones.
            ->assertSee('data-party-host', escape: false)
            ->assertSee('data-item-host', escape: false)
            ->assertSee('data-payments-host', escape: false)
            ->assertSee('data-totals-host', escape: false);

        // §12: a confirmation step, showing the server's own figures.
        $response->assertSee('id="confirm-bill-modal"', escape: false)
            ->assertSee('data-confirm-post', escape: false);

        // §26: the unfinished bill survives a closed tab.
        $response->assertSee('data-restored', escape: false)
            ->assertSee('You had an unfinished bill', escape: false);

        // §4: a customer who is not on the books yet, added without losing the
        // bill — a drawer, so it opens over a form rather than as a second modal.
        // A shared partial too, since the Purchase milestone: this was a copy of
        // the counterparty screens' form with fewer fields and no validation.
        $response->assertSee('id="quick-party-drawer"', escape: false);

        // §5's quick-add, now a shared partial rather than a copy per screen.
        $response->assertSee('id="quick-item-modal"', escape: false);

        foreach (['sale', 'purchase', 'workshop'] as $kind) {
            $response->assertSee('data-kind="'.$kind.'"', escape: false);
        }
    }

    /* ---------------------------------------------------------------------
     | The modules that are shipped but not yet switched on
     |
     | Their markup is still in the build and still has to be right, so it is
     | rendered directly rather than fetched. What is gone from these assertions
     | is `data-page`: a fragment has no page shell around it.
     | ------------------------------------------------------------------ */

    public function test_the_accounting_module_renders_its_three_tabs_and_modal(): void
    {
        $view = $this->view('modules.accounts');

        // The three views of the books, and the body each one paints into.
        $view->assertSee('id="accounting-tabs"', escape: false)
            ->assertSee('data-tab="ledger"', escape: false)
            ->assertSee('data-tab="journal"', escape: false)
            ->assertSee('data-tab="coa"', escape: false)
            ->assertSee('id="ledger-body"', escape: false)
            ->assertSee('id="journal-body"', escape: false)
            ->assertSee('id="coa-groups"', escape: false);

        $view->assertSee('id="ledger-drawer"', escape: false)
            ->assertSee('id="journal-drawer"', escape: false)
            ->assertSee('id="account-form"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ACCOUNTS"', escape: false);

        /*
        | The three grants this screen spans. The journal tab and every balance
        | are gated separately from the chart the page itself is gated on, and
        | the markup has to carry those marks or the JS has nothing to strip for
        | a caller who holds READ:ACCOUNTS alone.
        */
        $view->assertSee('data-requires-permission="READ:TRANSACTIONS"', escape: false)
            ->assertSee('data-ledger-only', escape: false);

        // Sources come from the enum, so the journal pills cannot drift from
        // the sources a transaction can actually carry.
        foreach (TransactionSource::cases() as $source) {
            $view->assertSee('data-pill="'.$source->value.'"', escape: false)
                ->assertSee($source->label(), escape: false);
        }

        // Type options come from the enum, so the filter and the create form
        // cannot drift from the five types the ledger actually supports.
        foreach (AccountType::cases() as $type) {
            $view->assertSee('value="'.$type->value.'"', escape: false)
                ->assertSee($type->label(), escape: false);
        }
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

    public function test_the_journal_module_renders_the_double_entry_grid(): void
    {
        $view = $this->view('modules.journal');

        $view->assertSee('id="journal-rows"', escape: false)
            ->assertSee('id="journal-form"', escape: false)
            ->assertSee('id="journal-lines"', escape: false)
            // A drawer rather than a centred modal: reading a voucher is a glance
            // mid-scan, and the row it came from should stay visible behind it.
            ->assertSee('id="voucher-drawer"', escape: false)
            ->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        // Saving a draft and posting are separate controls: committing to the
        // ledger must never happen as a side effect of saving.
        $view->assertSee('id="save-draft"', escape: false)
            ->assertSee('Post entry', escape: false);

        // Optional and genuinely so: a depreciation entry or a correcting
        // journal has no counterparty.
        $view->assertSee('id="journal-party"', escape: false)
            ->assertSee('name="party_id"', escape: false)
            ->assertSee('No counterparty', escape: false);

        // Status options come from the enum, so the filter cannot drift from it.
        foreach (TransactionStatus::cases() as $status) {
            $view->assertSee('value="'.$status->value.'"', escape: false)
                ->assertSee($status->label(), escape: false);
        }

        // Types too, so the filter cannot drift from what the engine can post.
        foreach (TransactionType::cases() as $type) {
            $view->assertSee('value="'.$type->value.'"', escape: false)
                ->assertSee($type->label(), escape: false);
        }
    }

    public function test_the_transactions_module_renders_four_tabs(): void
    {
        $view = $this->view('modules.journal');
        $content = (string) $view;

        $view->assertSee('id="txn-tabs"', escape: false)
            ->assertSee('role="tablist"', escape: false);

        foreach (['sales', 'purchases', 'expenses', 'drafts'] as $tab) {
            $view->assertSee('data-tab="'.$tab.'"', escape: false);
        }

        foreach (['Sales', 'Purchase Bills', 'Expenses', 'Drafts'] as $label) {
            $view->assertSee($label, escape: false);
        }

        // Sales is the tab the module opens on, and exactly one tab is selected.
        $this->assertSame(
            1,
            substr_count($content, 'aria-selected="true"'),
            'Exactly one tab should be selected when the module is served.',
        );

        // The head is rendered from JS because the columns differ per tab, so
        // the markup ships it empty rather than with one tab's columns hardcoded
        // — those would flash the wrong headings before the rows arrive.
        $view->assertSee('<thead id="journal-head"></thead>', escape: false);
    }

    public function test_the_transactions_module_offers_a_receipt_a_payment_and_a_journal(): void
    {
        // Three separate actions, not one "new transaction" that then asks what
        // kind: collecting from a customer, paying a supplier and writing a
        // correcting voucher are different jobs, and a receipt is much the
        // commonest — it should be one click.
        $this->view('modules.journal')
            ->assertSee('id="new-receipt"', escape: false)
            ->assertSee('id="new-payment"', escape: false)
            ->assertSee('id="new-journal"', escape: false)
            ->assertSee('Record receipt', escape: false)
            ->assertSee('Record payment', escape: false);
    }

    public function test_the_settlement_form_collects_a_party_and_a_payment_split(): void
    {
        $view = $this->view('modules.journal');

        $view->assertSee('id="settlement-modal"', escape: false)
            ->assertSee('id="settlement-form"', escape: false)
            // One row per tender: ₹2,000 from the till and ₹3,000 by UPI is one
            // receipt moving two accounts, each reconciled separately.
            ->assertSee('id="settlement-rows"', escape: false)
            ->assertSee('id="add-payment-row"', escape: false)
            // Required, where a journal's counterparty is optional.
            ->assertSee('name="party_id"', escape: false)
            // Saving a draft and recording are separate controls, as everywhere.
            ->assertSee('id="save-settlement-draft"', escape: false);

        // The payment modes are fetched from GET /transactions/meta rather than
        // baked into the markup, so the form's reference labels cannot drift from
        // the server's rules about which ones are required.
        $view->assertDontSee('value="cheque"', escape: false);
    }

    public function test_the_ledger_module_renders_the_trial_balance_shell(): void
    {
        $this->view('modules.ledger')
            ->assertSee('id="ledger-rows"', escape: false)
            ->assertSee('id="reconciliation"', escape: false)
            ->assertSee('id="filter-account"', escape: false)
            // The default view is the trial balance over every account.
            ->assertSee('Trial balance', escape: false);
    }

    /**
     * The list — M20's §23 columns.
     *
     * The bill *form* is not here: it is `/bills/new`, a page rather than a modal
     * (decision D8). What this module keeps is the list, the read-only bill view
     * and the expense form, which stays separate because an expense is a
     * different kind of money from a purchase.
     */
    public function test_the_bills_module_renders_the_list_and_the_expense_form(): void
    {
        $view = $this->view('modules.bills');

        $view->assertSee('id="bills-body"', escape: false)
            ->assertSee('id="expense-form"', escape: false)
            ->assertSee('id="bill-modal"', escape: false)
            ->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        // Straight to the counter. The two-step chooser it replaced asked "how
        // would you like to enter it?" and had exactly one live answer.
        $view->assertSee('href="'.route('bills.create').'"', escape: false)
            ->assertSee('data-new-bill', escape: false)
            ->assertDontSee('id="new-transaction-modal"', escape: false)
            ->assertDontSee('id="bill-form"', escape: false);

        // §23's four money columns. They were impossible before M16 linked a
        // receipt to the invoice it settled, and their presence is the whole
        // reason this screen was rewritten.
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
     * Sending the customer their invoice — M20's level-3 dialog.
     *
     * Declared beside the drawer rather than inside either level-1 surface, for
     * the reason the drawer is: the workspace's swap between the form and the
     * list must not be able to detach it with one of them.
     */
    public function test_the_sales_module_carries_the_share_dialog_above_the_drawer(): void
    {
        $html = $this->get('/modules/sales')->assertOk()->getContent();

        $this->assertStringContainsString('id="sales-share-modal"', (string) $html);

        // Level 3 over the drawer's level 2, and below the confirmation's 60 —
        // so revoking can put a confirm over this without either disappearing.
        $this->assertMatchesRegularExpression(
            '/id="sales-share-modal".*?z-index:\s*55/s',
            (string) $html,
        );

        // After both level-1 surfaces, never inside one.
        $this->assertGreaterThan(
            strpos((string) $html, 'data-ws-list'),
            strpos((string) $html, 'id="sales-share-modal"'),
            'The share dialog must be declared outside the swapped surfaces.',
        );
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

    /**
     * The bench — M19 and M21.
     */
    public function test_the_jobs_module_renders_its_list_and_its_booking_form(): void
    {
        $view = $this->view('modules.jobs');

        $view->assertSee('id="jobs-body"', escape: false)
            ->assertSee('id="job-modal"', escape: false)
            ->assertSee('id="job-form"', escape: false)
            // Gated on the workshop grant rather than on TRANSACTIONS: a job has
            // nothing in the books until somebody bills it.
            ->assertSee('data-requires-permission="WRITE:WORKSHOP_JOBS"', escape: false);

        // §23's columns.
        foreach (['Job', 'Customer', 'Motor', 'Complaint', 'Status'] as $column) {
            $view->assertSee('>'.$column.'</th>', escape: false);
        }

        // Every field of the motor is optional, and the form has to say so: a
        // pump wheeled in by a driver who does not know its brand still has to be
        // bookable, or the job card gets written on paper.
        $view->assertSee('whatever is known', escape: false);
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
            ->assertSee('id="adjustment-form"', escape: false)
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
        $this->assertArrayNotHasKey('reports', \App\Support\Modules::declared());

        $this->get('/modules/reports')->assertNotFound();
    }

    public function test_the_history_module_renders_its_filters(): void
    {
        $view = $this->view('modules.audit');

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

    public function test_the_opening_balances_module_renders_its_two_step_flow(): void
    {
        $view = $this->view('modules.opening');

        $view->assertSee('id="opening-form"', escape: false)
            ->assertSee('id="opening-csv"', escape: false)
            ->assertSee('id="preview-panel"', escape: false)
            ->assertSee('id="preview-rows"', escape: false)
            ->assertSee('id="history-rows"', escape: false)
            ->assertSee('id="reconciliation"', escape: false);

        // Checking and posting are two controls, never one. Committing a
        // workshop's whole financial history must not be something that
        // happened because a flag was left out.
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
        $view = $this->view('modules.workspace');

        $view->assertSee('id="workspace-form"', escape: false)
            ->assertSee('id="welcome-banner"', escape: false);

        foreach (['name', 'gstin', 'state_code', 'address', 'financial_year_start_month', 'timezone', 'books_start_date'] as $field) {
            $view->assertSee('name="'.$field.'"', escape: false);
        }

        // Currency is displayed, never edited — the tax engine is India-specific.
        $view->assertDontSee('name="currency"', escape: false);
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

        foreach (['/dashboard', '/bills/new'] as $path) {
            $content = $this->get($path)->assertOk()->getContent();

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $content);
            }
        }
    }
}
