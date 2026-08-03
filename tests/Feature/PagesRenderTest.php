<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ItemType;
use App\Enums\PartyRole;
use App\Enums\TenantStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\UnitOfMeasure;
use App\Enums\UserStatus;
use App\Models\ChartOfAccount;
use App\Models\Item;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Smoke tests for the Blade shells: they must compile and contain the design's
 * structure. Data itself is hydrated client-side, so there is nothing else to
 * assert server-side.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_renders(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertSee('Welcome back', escape: false)
            ->assertSee('Sign in to your AI Accounting Back Office', escape: false)
            ->assertSee('id="login-form"', escape: false)
            ->assertSee('name="email"', escape: false)
            ->assertSee('name="password"', escape: false);
    }

    public function test_the_dashboard_renders_every_section(): void
    {
        $response = $this->get('/dashboard')->assertOk();

        foreach (['Quick Actions', 'Needs Your Attention', 'Continue Working', 'Notifications', 'Recent Activity'] as $section) {
            $response->assertSee($section, escape: false);
        }

        $response->assertSee('Record Transaction', escape: false)
            ->assertSee('Low Stock Items', escape: false)
            ->assertSee('Voice Transaction', escape: false)
            ->assertSee('Load earlier activity', escape: false)
            // Amounts are rupee-formatted per the design.
            ->assertSee('₹14,500', escape: false);
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

    public function test_the_sidebar_marks_the_current_page(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    }

    public function test_the_root_path_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_users_page_renders_its_table_and_modal(): void
    {
        $response = $this->get('/users')->assertOk();

        $response->assertSee('id="users-body"', escape: false)
            ->assertSee('id="user-form"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="users"', escape: false)
            // The "New user" button is gated on the WRITE:USERS grant.
            ->assertSee('data-requires-permission="WRITE:USERS"', escape: false);

        // Status options come from the enum, so the filter cannot drift from it.
        foreach (UserStatus::cases() as $status) {
            $response->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    public function test_the_roles_page_renders_its_table_and_permission_matrix(): void
    {
        $this->get('/roles')
            ->assertOk()
            ->assertSee('id="roles-body"', escape: false)
            ->assertSee('id="role-form"', escape: false)
            ->assertSee('id="permission-matrix"', escape: false)
            ->assertSee('id="role-slug-preview"', escape: false)
            ->assertSee('data-page="roles"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ROLES"', escape: false);
    }

    public function test_the_accounting_page_renders_its_three_tabs_and_modal(): void
    {
        $response = $this->get('/accounts')->assertOk();

        // The three views of the books, and the body each one paints into.
        $response->assertSee('id="accounting-tabs"', escape: false)
            ->assertSee('data-tab="ledger"', escape: false)
            ->assertSee('data-tab="journal"', escape: false)
            ->assertSee('data-tab="coa"', escape: false)
            ->assertSee('id="ledger-body"', escape: false)
            ->assertSee('id="journal-body"', escape: false)
            ->assertSee('id="coa-groups"', escape: false);

        $response->assertSee('id="ledger-drawer"', escape: false)
            ->assertSee('id="journal-drawer"', escape: false)
            ->assertSee('id="account-form"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="accounts"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ACCOUNTS"', escape: false);

        /*
        | The three grants this screen spans. The journal tab and every balance
        | are gated separately from the chart the page itself is gated on, and
        | the markup has to carry those marks or the JS has nothing to strip for
        | a caller who holds READ:ACCOUNTS alone.
        */
        $response->assertSee('data-requires-permission="READ:TRANSACTIONS"', escape: false)
            ->assertSee('data-ledger-only', escape: false);

        // Sources come from the enum, so the journal pills cannot drift from
        // the sources a transaction can actually carry.
        foreach (TransactionSource::cases() as $source) {
            $response->assertSee('data-pill="'.$source->value.'"', escape: false)
                ->assertSee($source->label(), escape: false);
        }

        // Type options come from the enum, so the filter and the create form
        // cannot drift from the five types the ledger actually supports.
        foreach (AccountType::cases() as $type) {
            $response->assertSee('value="'.$type->value.'"', escape: false)
                ->assertSee($type->label(), escape: false);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: PartyRole}>
     */
    public static function counterpartyScreens(): array
    {
        return [
            'customers' => ['/customers', 'customers', 'Add Customer', PartyRole::Customer],
            'vendors' => ['/vendors', 'vendors', 'Add Vendor', PartyRole::Vendor],
        ];
    }

    #[DataProvider('counterpartyScreens')]
    public function test_a_counterparty_page_renders_its_table_and_modals(
        string $path,
        string $page,
        string $addLabel,
        PartyRole $role,
    ): void {
        $response = $this->get($path)->assertOk();

        $response->assertSee('id="parties-body"', escape: false)
            ->assertSee('id="party-form"', escape: false)
            // One counterparty is read in a drawer over the list, because their
            // history is read while thinking about them.
            ->assertSee('id="party-drawer"', escape: false)
            // The statement opens over the list rather than navigating away.
            ->assertSee('id="party-ledger-modal"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="'.$page.'"', escape: false)
            ->assertSee('data-requires-permission="WRITE:PARTIES"', escape: false)
            ->assertSee($addLabel, escape: false);

        // The statement is the combined ledger whichever screen opened it:
        // receivable and payable side by side, never netted, because for a
        // counterparty who is both the two are settled separately.
        $response->assertSee('Receivable', escape: false)
            ->assertSee('Payable', escape: false);

        // Both roles are offered on the form, with this screen's ticked. Ticking
        // the other is what keeps a dual-role counterparty one record instead of
        // two — which is what would split a single balance in half.
        foreach (PartyRole::cases() as $case) {
            $response->assertSee('value="'.$case->value.'"', escape: false)
                ->assertSee($case->label(), escape: false);
        }

        $response->assertSee(
            'name="roles" value="'.$role->value.'"',
            escape: false,
        );
    }

    #[DataProvider('counterpartyScreens')]
    public function test_a_counterparty_shell_exposes_no_records_to_anonymous_visitors(
        string $path,
        string $page,
        string $addLabel,
        PartyRole $role,
    ): void {
        // Every row arrives from the guarded API. A visitor must not learn who
        // a workshop trades with — or what they owe — from the HTML.
        $tenant = Tenant::factory()->create();

        $party = app(TenantContext::class)->runFor(
            $tenant,
            fn () => Party::factory()->create(['name' => 'Confidential Winding Co'])
        );

        $this->get($path)
            ->assertOk()
            ->assertDontSee($party->name, escape: false)
            ->assertDontSee($tenant->name, escape: false);
    }

    public function test_the_counterparty_nav_entries_are_gated_on_the_parties_grant(): void
    {
        // Two entries over one table, both on READ:PARTIES because they are the
        // same records — a counterparty holding both roles appears on both, as
        // one record with one combined ledger rather than two halves of a
        // balance that never meet.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:PARTIES"', escape: false)
            ->assertSee('Customers', escape: false)
            ->assertSee('Vendors', escape: false);
    }

    public function test_the_old_parties_url_still_lands_somewhere(): void
    {
        // The screen the two replaced. A redirect rather than a 404, so an
        // existing bookmark keeps working.
        $this->get('/parties')->assertRedirect('/customers');
    }

    public function test_the_items_page_renders_its_table_and_modals(): void
    {
        $response = $this->get('/items')->assertOk();

        $response->assertSee('id="items-body"', escape: false)
            ->assertSee('id="item-form"', escape: false)
            // Variants open over the list rather than navigating away: they are
            // read while thinking about the family. A tab of the item drawer
            // rather than a modal of their own, so getting to them never costs
            // the row you came from.
            ->assertSee('id="item-drawer"', escape: false)
            ->assertSee('data-tab="variants"', escape: false)
            ->assertSee('id="variant-form"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="items"', escape: false)
            ->assertSee('data-requires-permission="WRITE:ITEMS"', escape: false);

        // Type options come from the enum, so the filter and the create form
        // cannot drift from the four kinds of thing the catalogue supports.
        foreach (ItemType::cases() as $type) {
            $response->assertSee('value="'.$type->value.'"', escape: false)
                ->assertSee($type->label(), escape: false);
        }

        // Units too — a fitter must be able to say copper is weighed.
        foreach (UnitOfMeasure::cases() as $unit) {
            $response->assertSee('value="'.$unit->value.'"', escape: false);
        }
    }

    public function test_the_items_page_carries_a_review_queue(): void
    {
        $response = $this->get('/items')->assertOk();

        // The draft queue is surfaced rather than hidden behind a filter: nobody
        // goes looking for a queue they were not told about. The count itself is
        // filled from GET /items/meta, so only the shell is here.
        $response->assertSee('id="draft-banner"', escape: false)
            ->assertSee('id="draft-banner-title"', escape: false)
            ->assertSee('Auto-created from an import or a capture', escape: false);
    }

    public function test_every_stock_bearing_element_on_the_items_page_declares_its_gate(): void
    {
        $response = $this->get('/items')->assertOk();

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
        $response = $this->get('/items')->assertOk();

        $response->assertSee('id="variant-attributes"', escape: false)
            ->assertDontSee('data-attribute="hp"', escape: false)
            ->assertDontSee('data-attribute="gauge"', escape: false);
    }

    public function test_the_items_shell_exposes_no_catalogue_to_anonymous_visitors(): void
    {
        // Every row arrives from the guarded API. A visitor must not learn what a
        // workshop deals in — or what it charges — from the HTML.
        $tenant = Tenant::factory()->create();

        $item = app(TenantContext::class)->runFor(
            $tenant,
            fn () => Item::factory()->motor()->create(['name' => 'Confidential Motor Line'])
        );

        $this->get('/items')
            ->assertOk()
            ->assertDontSee($item->name, escape: false)
            ->assertDontSee($tenant->name, escape: false);
    }

    public function test_the_items_nav_entry_is_gated_on_the_items_grant(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:ITEMS"', escape: false)
            // Labelled for what it is until M8: there are no quantities behind it
            // yet, and a nav entry promising "Inventory" that shows no stock is
            // worse than one that promises less.
            ->assertSee('Items', escape: false);
    }

    public function test_the_journal_form_offers_an_optional_counterparty(): void
    {
        // Optional and genuinely so: a depreciation entry or a correcting
        // journal has no counterparty.
        $this->get('/journal')
            ->assertOk()
            ->assertSee('id="journal-party"', escape: false)
            ->assertSee('name="party_id"', escape: false)
            ->assertSee('No counterparty', escape: false);
    }

    public function test_the_journal_page_renders_the_double_entry_grid(): void
    {
        $response = $this->get('/journal')->assertOk();

        $response->assertSee('id="journal-rows"', escape: false)
            ->assertSee('id="journal-form"', escape: false)
            ->assertSee('id="journal-lines"', escape: false)
            // A drawer rather than a centred modal: reading a voucher is a glance
            // mid-scan, and the row it came from should stay visible behind it.
            ->assertSee('id="voucher-drawer"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="journal"', escape: false)
            ->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        // Saving a draft and posting are separate controls: committing to the
        // ledger must never happen as a side effect of saving.
        $response->assertSee('id="save-draft"', escape: false)
            ->assertSee('Post entry', escape: false);

        // Status options come from the enum, so the filter cannot drift from it.
        foreach (TransactionStatus::cases() as $status) {
            $response->assertSee('value="'.$status->value.'"', escape: false)
                ->assertSee($status->label(), escape: false);
        }
    }

    public function test_the_transactions_page_renders_four_tabs(): void
    {
        $response = $this->get('/journal')->assertOk();

        $response->assertSee('id="txn-tabs"', escape: false)
            ->assertSee('role="tablist"', escape: false);

        foreach (['sales', 'purchases', 'expenses', 'drafts'] as $tab) {
            $response->assertSee('data-tab="'.$tab.'"', escape: false);
        }

        foreach (['Sales', 'Purchase Bills', 'Expenses', 'Drafts'] as $label) {
            $response->assertSee($label, escape: false);
        }

        // Sales is the tab the page opens on, and exactly one tab is selected.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'aria-selected="true"'),
            'Exactly one tab should be selected when the page is served.',
        );

        // The head is rendered from JS because the columns differ per tab, so
        // the shell ships it empty rather than with one tab's columns hardcoded
        // — those would flash the wrong headings before the rows arrive.
        $response->assertSee('<thead id="journal-head"></thead>', escape: false);
    }

    public function test_the_transactions_page_offers_a_receipt_a_payment_and_a_journal(): void
    {
        // Three separate actions, not one "new transaction" that then asks what
        // kind: collecting from a customer, paying a supplier and writing a
        // correcting voucher are different jobs, and a receipt is much the
        // commonest — it should be one click.
        $response = $this->get('/journal')->assertOk();

        $response->assertSee('id="new-receipt"', escape: false)
            ->assertSee('id="new-payment"', escape: false)
            ->assertSee('id="new-journal"', escape: false)
            ->assertSee('Record receipt', escape: false)
            ->assertSee('Record payment', escape: false);
    }

    public function test_the_settlement_form_collects_a_party_and_a_payment_split(): void
    {
        $response = $this->get('/journal')->assertOk();

        $response->assertSee('id="settlement-modal"', escape: false)
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
        $response->assertDontSee('value="cheque"', escape: false);
    }

    public function test_the_transaction_list_can_be_filtered_by_type(): void
    {
        $response = $this->get('/journal')->assertOk();

        $response->assertSee('id="filter-type"', escape: false);

        // Types come from the enum, so the filter cannot drift from what the
        // engine can actually post.
        foreach (TransactionType::cases() as $type) {
            $response->assertSee('value="'.$type->value.'"', escape: false)
                ->assertSee($type->label(), escape: false);
        }
    }

    public function test_the_ledger_page_renders_the_trial_balance_shell(): void
    {
        $this->get('/ledger')
            ->assertOk()
            ->assertSee('id="ledger-rows"', escape: false)
            ->assertSee('id="reconciliation"', escape: false)
            ->assertSee('id="filter-account"', escape: false)
            ->assertSee('data-page="ledger"', escape: false)
            // The default view is the trial balance over every account.
            ->assertSee('Trial balance', escape: false);
    }

    public function test_the_ledger_shell_exposes_no_figures_to_anonymous_visitors(): void
    {
        // Every number arrives from the guarded API. A visitor must not learn a
        // workshop's position from the HTML.
        $tenant = Tenant::factory()->create(['name' => 'Confidential Motors']);

        foreach (['/journal', '/ledger'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee($tenant->name, escape: false);
        }
    }

    public function test_the_transactions_and_ledger_nav_entries_are_separately_gated(): void
    {
        // Capturing events and reading the whole financial position are
        // different authorities — DATA_ENTRY holds the first and not the second.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:TRANSACTIONS"', escape: false)
            ->assertSee('data-requires-permission="READ:LEDGER"', escape: false);
    }

    public function test_the_accounting_nav_entry_is_permission_gated(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:ACCOUNTS"', escape: false);
    }

    public function test_workshop_nav_entries_also_require_a_workspace(): void
    {
        // Permissions and tenancy are separate gates. A platform super-admin
        // holds the wildcard grant, so a permission check alone would show them
        // a chart of accounts they cannot load — authority is not membership.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-workspace', escape: false);
    }

    public function test_the_sidebar_does_not_hardcode_a_workshop_name(): void
    {
        // The workshop is painted client-side from /auth/me. A hardcoded name
        // made it impossible to tell which workshop — or whether any — the
        // session belonged to.
        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('XYZ Workshop', escape: false)
            ->assertSee('data-workspace-name', escape: false);
    }

    public function test_the_accounts_shell_exposes_no_ledger_data_to_anonymous_visitors(): void
    {
        // Every account arrives from the guarded API. A visitor must not learn
        // a workshop's chart — including any account it added itself — from the
        // HTML.
        $tenant = Tenant::factory()->create();

        $account = app(TenantContext::class)->runFor($tenant, fn () => ChartOfAccount::factory()
            ->ofType(AccountType::Expense)
            ->create(['code' => '5900', 'name' => 'Confidential Retainer']));

        $this->get('/accounts')
            ->assertOk()
            ->assertDontSee($account->name, escape: false)
            ->assertDontSee($tenant->name, escape: false);
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

    public function test_the_login_page_only_offers_sign_up_when_it_is_enabled(): void
    {
        $this->get('/login')->assertOk()->assertSee('Create your workshop', escape: false);

        config()->set('tenancy.allow_public_signup', false);

        $this->get('/login')->assertOk()->assertDontSee('Create your workshop', escape: false);
    }

    public function test_the_tenants_page_renders_its_table_and_owner_block(): void
    {
        $response = $this->get('/tenants')->assertOk();

        $response->assertSee('id="tenants-body"', escape: false)
            ->assertSee('id="tenant-form"', escape: false)
            ->assertSee('id="tenant-owner-block"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="tenants"', escape: false)
            ->assertSee('data-requires-permission="WRITE:TENANTS"', escape: false);

        // Status options come from the enum, so the filter cannot drift from it.
        foreach (TenantStatus::cases() as $status) {
            $response->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    public function test_the_workspace_page_renders_identity_and_book_settings(): void
    {
        $response = $this->get('/workspace')->assertOk();

        $response->assertSee('id="workspace-form"', escape: false)
            ->assertSee('data-page="workspace"', escape: false)
            ->assertSee('id="welcome-banner"', escape: false);

        foreach (['name', 'gstin', 'state_code', 'address', 'financial_year_start_month', 'timezone', 'books_start_date'] as $field) {
            $response->assertSee('name="'.$field.'"', escape: false);
        }

        // Currency is displayed, never edited — the tax engine is India-specific.
        $response->assertDontSee('name="currency"', escape: false);
    }

    public function test_the_stock_page_renders_its_table_and_count_form(): void
    {
        $response = $this->get('/stock')->assertOk();

        $response->assertSee('id="stock-body"', escape: false)
            ->assertSee('id="adjustment-form"', escape: false)
            ->assertSee('id="stock-card-modal"', escape: false)
            ->assertSee('id="reconciliation"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="stock"', escape: false)
            // The only control that changes stock, and it posts a transaction —
            // hence WRITE:TRANSACTIONS rather than a stock-specific grant.
            ->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        // Negative stock has a tile of its own. It is a data problem rather than
        // a shortage, and folding it into "low" would train people to ignore it.
        $response->assertSee('id="stat-negative"', escape: false)
            ->assertSee('id="stat-low"', escape: false);

        // A service can never hold stock, so it must not be offered as a filter.
        foreach (ItemType::cases() as $type) {
            if ($type->canHoldStock()) {
                $response->assertSee('value="'.$type->value.'"', escape: false);
            } else {
                $response->assertDontSee('value="'.$type->value.'"', escape: false);
            }
        }
    }

    public function test_the_bills_page_renders_its_table_and_three_forms(): void
    {
        $response = $this->get('/bills')->assertOk();

        $response->assertSee('id="bills-body"', escape: false)
            ->assertSee('id="bill-form"', escape: false)
            ->assertSee('id="expense-form"', escape: false)
            ->assertSee('id="bill-modal"', escape: false)
            ->assertSee('data-page="bills"', escape: false)
            ->assertSee('data-requires-permission="WRITE:TRANSACTIONS"', escape: false);

        // One "New" button per the design, in both of its places — the page
        // header and the list toolbar — opening the chooser rather than a form.
        $response->assertSee('id="new-transaction-modal"', escape: false);

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'data-new-transaction'),
            'Both of the design\'s "New" buttons should be on the page.',
        );

        // Its first step offers the three kinds the API has routes for, and its
        // second the three ways in. One form serves a sale and a purchase — the
        // payload is identical and the direction is the route — and a separate
        // one an expense, which is a different kind of money.
        foreach (['sale', 'purchase', 'expense'] as $kind) {
            $response->assertSee('data-kind="'.$kind.'"', escape: false);
        }

        foreach (['ai', 'upload', 'manual'] as $method) {
            $response->assertSee('data-method="'.$method.'"', escape: false);
        }

        // The two that are M15's are shown, and shown disabled. A card that
        // looked pickable and then did nothing would be worse than none, so
        // this is asserted rather than left to the markup.
        foreach (['ai', 'upload'] as $method) {
            $this->assertMatchesRegularExpression(
                '/data-method="'.$method.'"[^>]*\bdisabled\b/',
                $response->getContent(),
                "The {$method} input method has no endpoint behind it yet and must not be selectable.",
            );
        }

        // Statuses come from the enum, so the filter cannot drift from it.
        foreach (TransactionStatus::cases() as $status) {
            $response->assertSee('value="'.$status->value.'"', escape: false);
        }
    }

    public function test_the_reports_page_renders_its_four_reports(): void
    {
        $response = $this->get('/reports')->assertOk();

        $response->assertSee('id="report-tabs"', escape: false)
            ->assertSee('id="report-rows"', escape: false)
            ->assertSee('id="report-stats"', escape: false)
            ->assertSee('id="reconciliation"', escape: false)
            ->assertSee('data-page="reports"', escape: false);

        foreach (['day-book', 'profit-and-loss', 'gst', 'drafts'] as $report) {
            $response->assertSee('data-report="'.$report.'"', escape: false);
        }
    }

    public function test_the_reports_page_does_not_hardcode_the_financial_year(): void
    {
        // The presets come from GET /reports/meta, because "this financial year"
        // depends on the workshop's own year-start setting — a copy in the
        // markup would be right until somebody changed it on the settings
        // screen, and then every bookmark would report the wrong twelve months.
        $response = $this->get('/reports')->assertOk();

        $response->assertSee('id="filter-period"', escape: false)
            ->assertDontSee('value="this_financial_year"', escape: false);
    }

    public function test_the_reports_page_does_not_repeat_the_trial_balance(): void
    {
        // It lives on the Ledger screen beside the account it drills into. A
        // second copy would be a second thing to keep in step.
        $this->get('/reports')
            ->assertOk()
            ->assertDontSee('data-report="trial-balance"', escape: false);
    }

    public function test_the_reports_shell_exposes_no_figures_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Confidential Motors']);

        $this->get('/reports')
            ->assertOk()
            ->assertDontSee($tenant->name, escape: false);
    }

    public function test_the_reports_nav_entry_is_gated_on_the_ledger_grant(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Reports', escape: false)
            ->assertSee('data-requires-permission="READ:LEDGER"', escape: false);
    }

    public function test_the_history_page_renders_its_filters(): void
    {
        $response = $this->get('/audit')->assertOk();

        $response->assertSee('id="audit-rows"', escape: false)
            ->assertSee('id="filter-resource"', escape: false)
            ->assertSee('id="filter-action"', escape: false)
            ->assertSee('id="filter-actor"', escape: false)
            ->assertSee('data-page="audit"', escape: false);
    }

    public function test_the_history_page_does_not_hardcode_what_can_be_changed(): void
    {
        // The options come from GET /audit-logs/meta. The list of things a
        // workshop can change grows with every module, and a copy in the markup
        // would silently stop offering the newest one.
        $this->get('/audit')
            ->assertOk()
            ->assertDontSee('value="party"', escape: false)
            ->assertDontSee('value="archived"', escape: false);
    }

    public function test_the_history_page_explains_why_transactions_are_absent(): void
    {
        // The commonest question this screen provokes. A posted transaction
        // cannot be edited or deleted at all, so it has no history to show —
        // and an unexplained absence reads as a missing feature.
        $this->get('/audit')
            ->assertOk()
            ->assertSee('cannot be edited or deleted', escape: false);
    }

    public function test_the_history_shell_exposes_nothing_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Confidential Motors']);

        $this->get('/audit')
            ->assertOk()
            ->assertDontSee($tenant->name, escape: false);
    }

    public function test_the_history_nav_entry_is_gated_on_the_audit_grant(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('History', escape: false)
            ->assertSee('data-requires-permission="READ:AUDIT"', escape: false);
    }

    public function test_the_uploads_page_separates_what_is_in_flight_from_what_is_stored(): void
    {
        $response = $this->get('/uploads')->assertOk();

        // Two lists, deliberately. A file still travelling is not yet one of the
        // workshop's records, and a row that appeared in the library and might
        // then vanish would be worse than one that never claimed to be there.
        $response->assertSee('id="upload-queue"', escape: false)
            ->assertSee('id="upload-rows"', escape: false)
            ->assertSee('id="upload-input"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="uploads"', escape: false);
    }

    public function test_the_uploads_page_does_not_hardcode_what_may_be_uploaded(): void
    {
        // `accept` is filled in from GET /attachments/meta, so the picker offers
        // exactly what the server takes. A list written here would be right
        // until an operator raised a limit, and would then refuse files the API
        // would have accepted.
        $this->get('/uploads')
            ->assertOk()
            ->assertDontSee('accept="image/jpeg', escape: false);
    }

    public function test_the_uploads_page_promises_that_nothing_blocks(): void
    {
        $this->get('/uploads')
            ->assertOk()
            ->assertSee('checked in the background', escape: false);
    }

    public function test_the_uploads_nav_entry_is_gated_on_the_attachments_grant(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Uploads', escape: false)
            ->assertSee('data-requires-permission="READ:ATTACHMENTS"', escape: false);
    }

    public function test_the_opening_balances_page_renders_its_two_step_flow(): void
    {
        $response = $this->get('/opening')->assertOk();

        $response->assertSee('id="opening-form"', escape: false)
            ->assertSee('id="opening-csv"', escape: false)
            ->assertSee('id="preview-panel"', escape: false)
            ->assertSee('id="preview-rows"', escape: false)
            ->assertSee('id="history-rows"', escape: false)
            ->assertSee('id="reconciliation"', escape: false)
            ->assertSee('id="confirm-modal"', escape: false)
            ->assertSee('data-page="opening"', escape: false);

        // Checking and posting are two controls, never one. Committing a
        // workshop's whole financial history must not be something that
        // happened because a flag was left out.
        $response->assertSee('id="preview-opening"', escape: false)
            ->assertSee('id="import-opening"', escape: false)
            ->assertSee('Post these balances', escape: false);

        // Declaring what the workshop was worth at go-live is a setup act, so
        // the panel is gated on UPDATE:WORKSPACE rather than WRITE:TRANSACTIONS
        // — a data-entry user holds the second and not the first.
        $response->assertSee('data-requires-permission="UPDATE:WORKSPACE"', escape: false);
    }

    public function test_the_opening_balances_page_does_not_hardcode_the_column_rules(): void
    {
        // The column guide is filled from GET /opening-balances/meta, because a
        // copy of the parser's vocabulary in the markup is a copy that drifts —
        // and the drift shows up as instructions that produce a refused file.
        $response = $this->get('/opening')->assertOk();

        $response->assertSee('id="column-guide"', escape: false)
            ->assertDontSee('bulk_material', escape: false);
    }

    public function test_the_opening_balances_shell_exposes_no_figures_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Confidential Motors']);

        $this->get('/opening')
            ->assertOk()
            ->assertDontSee($tenant->name, escape: false);
    }

    public function test_the_opening_balances_nav_entry_sits_with_settings_and_is_gated(): void
    {
        // Used once per workshop, so it belongs beside Settings rather than
        // among the screens somebody opens every day.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Opening balances', escape: false)
            ->assertSee('data-requires-permission="UPDATE:WORKSPACE"', escape: false);
    }

    public function test_the_stock_nav_entry_is_gated_on_its_own_grant(): void
    {
        // Holding the catalogue is not holding the position — the same split
        // ACCOUNTS and LEDGER already make.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:STOCK"', escape: false)
            ->assertSee('data-requires-permission="READ:ITEMS"', escape: false);
    }

    public function test_the_stock_shell_exposes_no_quantities_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create();

        $item = app(TenantContext::class)->runFor($tenant, fn () => Item::factory()->create([
            'name' => 'Confidential Bearing',
        ]));

        $this->get('/stock')
            ->assertOk()
            ->assertDontSee($item->name, escape: false);
    }

    public function test_the_workshops_and_settings_nav_entries_are_gated(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            // Platform authority over every workshop.
            ->assertSee('data-requires-permission="READ:TENANTS"', escape: false)
            // The caller's own workshop — needs membership too.
            ->assertSee('data-requires-permission="READ:WORKSPACE"', escape: false);
    }

    public function test_the_tenant_shell_exposes_no_workshops_to_anonymous_visitors(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Confidential Motors']);

        $this->get('/tenants')
            ->assertOk()
            ->assertDontSee($tenant->name, escape: false)
            ->assertDontSee($tenant->slug, escape: false);
    }

    public function test_the_admin_shells_expose_no_records_to_anonymous_visitors(): void
    {
        // Both pages are public shells; rows arrive from the guarded API only.
        $user = User::factory()->create(['name' => 'Harshita Sharma']);
        $role = Role::factory()->create(['name' => 'Secret Role']);

        $this->get('/users')
            ->assertOk()
            ->assertDontSee($user->email, escape: false);

        $this->get('/roles')
            ->assertOk()
            ->assertDontSee($role->name, escape: false);
    }

    public function test_every_authenticated_page_carries_a_sign_out_control(): void
    {
        // The handler is delegated from the document, so the only thing the
        // markup has to guarantee is the hook and an accessible name.
        foreach (['/dashboard', '/accounts', '/customers', '/vendors', '/items', '/stock', '/bills', '/journal', '/ledger', '/reports', '/opening', '/uploads', '/audit', '/workspace', '/tenants', '/users', '/roles'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('data-logout', escape: false)
                ->assertSee('aria-label="Sign out"', escape: false);
        }
    }

    public function test_the_admin_nav_entries_are_permission_gated(): void
    {
        // Hidden by default and only revealed client-side once /auth/me confirms
        // the grant, so a viewer without READ:USERS never sees the entry.
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-requires-permission="READ:USERS"', escape: false)
            ->assertSee('data-requires-permission="READ:ROLES"', escape: false);
    }
}
