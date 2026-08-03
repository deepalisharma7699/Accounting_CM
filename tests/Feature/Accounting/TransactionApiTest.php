<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Models\ChartOfAccount;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The HTTP surface of the ledger: what a client can and cannot do to the books.
 */
class TransactionApiTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
            ['UPDATE', 'TRANSACTIONS'], ['DELETE', 'TRANSACTIONS'],
            ['READ', 'LEDGER'], ['READ', 'ACCOUNTS'],
        ]);
    }

    private function accountId(SystemAccount $key): int
    {
        return $this->accountFor($this->tenant, $key)->id;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    private function journal(bool $post = true, ?array $lines = null, ?string $date = null): array
    {
        return [
            'date' => $date ?? now()->toDateString(),
            'notes' => 'Counter sale',
            'post' => $post,
            'lines' => $lines ?? [
                ['account_id' => $this->accountId(SystemAccount::Cash), 'debit' => '5000.00'],
                ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '5000.00'],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | Posting
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_owner_can_post_a_manual_journal(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal())
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.type', 'journal')
            ->assertJsonPath('data.source', 'manual')
            // A decimal string, never a JSON number — a client that parses
            // 5000.00 as a float has already lost precision.
            ->assertJsonPath('data.total', '5000.00')
            ->assertJsonPath('data.line_count', 2)
            ->assertJsonPath('data.created_by', $this->owner->name);

        $this->assertSame('5000.00', $response->json('data.lines.0.debit'));
        $this->assertSame('0.00', $response->json('data.lines.0.credit'));

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_unbalanced_journal_is_refused_with_the_difference(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(lines: [
                ['account_id' => $this->accountId(SystemAccount::Cash), 'debit' => '5000.00'],
                ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '4500.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOURNAL_UNBALANCED')
            ->assertJsonPath('error.details.difference', '500.00');

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::count()));
    }

    #[Test]
    public function a_single_line_journal_is_refused_by_validation(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(lines: [
                ['account_id' => $this->accountId(SystemAccount::Cash), 'debit' => '100.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function a_line_with_an_amount_in_both_columns_is_refused(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(lines: [
                ['account_id' => $this->accountId(SystemAccount::Cash), 'debit' => '100.00', 'credit' => '100.00'],
                ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '100.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOURNAL_LINE_INVALID')
            ->assertJsonPath('error.details.line', 1);
    }

    #[Test]
    public function an_amount_with_three_decimal_places_is_refused_rather_than_rounded(): void
    {
        // A client sending 100.005 has a bug. Silently posting 100.01 hides it.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(lines: [
                ['account_id' => $this->accountId(SystemAccount::Cash), 'debit' => '100.005'],
                ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '100.005'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function posting_must_be_asked_for_explicitly(): void
    {
        $payload = $this->journal();
        unset($payload['post']);

        // Committing to the ledger must never be something that happened
        // because a field was left out.
        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function an_account_from_another_workshop_is_refused(): void
    {
        $other = Tenant::factory()->create();
        $theirCash = $this->accountFor($other, SystemAccount::Cash);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(lines: [
                ['account_id' => $theirCash->id, 'debit' => '100.00'],
                ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '100.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOURNAL_ACCOUNT_UNKNOWN');
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_is_saved_without_touching_the_ledger_and_posts_later(): void
    {
        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(post: false))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_editable', true)
            ->json('data.id');

        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Cash));

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$draft}", [
                'notes' => 'Corrected note',
                'lines' => [
                    ['account_id' => $this->accountId(SystemAccount::Bank), 'debit' => '6000.00'],
                    ['account_id' => $this->accountId(SystemAccount::Sales), 'credit' => '6000.00'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.total', '6000.00')
            ->assertJsonPath('data.notes', 'Corrected note');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$draft}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.is_editable', false);

        $this->assertSame('6000.00', $this->balanceOf($this->tenant, SystemAccount::Bank));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_draft_can_be_discarded(): void
    {
        $draft = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(post: false))
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$draft}")
            ->assertOk();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$draft}")
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Immutability over HTTP
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_posted_transaction_cannot_be_edited_or_deleted(): void
    {
        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal())
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->patchJson("/api/v1/transactions/{$id}", ['notes' => 'Tampered'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TRANSACTION_IMMUTABLE');

        $this->withHeaders($this->authHeader($this->owner))
            ->deleteJson("/api/v1/transactions/{$id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TRANSACTION_IMMUTABLE');

        $this->assertSame('Counter sale', $this->actingForTenant(
            $this->tenant,
            fn () => Transaction::findOrFail($id)->notes
        ));
    }

    #[Test]
    public function a_mistake_is_corrected_with_a_reversing_entry(): void
    {
        $id = $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal())
            ->json('data.id');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson("/api/v1/transactions/{$id}/reverse", ['reason' => 'Wrong account'])
            ->assertCreated()
            ->assertJsonPath('data.reverses_id', $id)
            ->assertJsonPath('data.notes', 'Wrong account')
            ->assertJsonPath('data.total', '5000.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertBooksBalance($this->tenant, 'after a reversal over HTTP');
    }

    /* ---------------------------------------------------------------------
     | Listing
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_list_filters_by_status_date_and_account(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00', date: '2026-05-01');
        $this->postSimpleJournal($this->tenant, SystemAccount::Bank, SystemAccount::ServiceIncome, '200.00', date: '2026-06-01');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(post: false));

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'draft');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', '100.00');

        // Everything that touched one account — the drill-down from a ledger
        // line back to the events behind it.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?account_id='.$this->accountId(SystemAccount::Bank))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', '200.00');
    }

    #[Test]
    public function the_list_filters_by_several_types_at_once(): void
    {
        // What a tab on the transactions screen means: a set of types, not one.
        // A customer receipt belongs beside the invoice it settles.
        $customer = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => 'Alpha Motors',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [['mode' => 'cash', 'amount' => '250.00']],
            ])
            ->assertCreated();

        // The receipt only — the journal is not on this tab.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=sale&types[]=receipt')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'receipt');

        // Both, once the journal's type is asked for as well.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=receipt&types[]=journal')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // A type that is not a type is refused rather than dropped: silently
        // ignoring it would show a shorter list to somebody who believes they
        // are looking at a complete one.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=not-a-type')
            ->assertStatus(422);
    }

    #[Test]
    public function the_list_reports_what_was_settled_on_each_document(): void
    {
        $customer = $this->actingForTenant($this->tenant, fn () => Party::factory()->create([
            'name' => 'Alpha Motors',
            'roles' => [PartyRole::Customer->value],
        ]));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/receipt', [
                'date' => now()->toDateString(),
                'post' => true,
                'party_id' => $customer->id,
                'payments' => [
                    ['mode' => 'cash', 'amount' => '2000.00'],
                    ['mode' => 'upi', 'amount' => '3000.00'],
                ],
            ])
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=receipt')
            ->assertOk()
            // Both tenders, added on the digits — a receipt settled two ways is
            // one document that touched two accounts.
            ->assertJsonPath('data.0.paid', '5000.00')
            ->assertJsonPath('data.0.balance', '0.00');

        // A manual journal cannot carry a split at all, so it reports neither
        // figure. Zero here would read as "nothing has been paid", which invites
        // somebody to chase it.
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions?types[]=journal')
            ->assertOk()
            ->assertJsonMissingPath('data.0.paid')
            ->assertJsonMissingPath('data.0.balance');
    }

    #[Test]
    public function the_counts_endpoint_breaks_the_books_down_by_type_and_status(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');
        $this->postSimpleJournal($this->tenant, SystemAccount::Bank, SystemAccount::Sales, '200.00');

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(post: false))
            ->assertCreated();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/counts')
            ->assertOk()
            ->assertJsonPath('data.types.journal', 3)
            ->assertJsonPath('data.statuses.posted', 2)
            ->assertJsonPath('data.statuses.draft', 1);
    }

    #[Test]
    public function the_counts_endpoint_needs_the_read_grant(): void
    {
        [, $stranger] = $this->tenantWithUser([['READ', 'ITEMS']]);

        $this->withHeaders($this->authHeader($stranger))
            ->getJson('/api/v1/transactions/counts')
            ->assertForbidden();
    }

    #[Test]
    public function the_list_reports_the_types_that_can_actually_be_posted(): void
    {
        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions/meta')
            ->assertOk();

        // A type appears here exactly when its posting template is registered,
        // which is what stops a client offering a form for something the engine
        // would refuse. `journal` from M4, the two settlements from M6, the stock
        // adjustment from M8, the two bills from M9, the expense from M10 and
        // M11's opening balance.
        $this->assertSame(
            ['journal', 'payment', 'receipt', 'stock_adjustment', 'sale', 'purchase', 'expense', 'opening'],
            collect($response->json('data.types'))->pluck('value')->all(),
        );

        // An opening balance is the one postable type with no route of its own
        // under /transactions: it is composed by M11's importer, which resolves
        // a whole file at once, and a hand-written POST that bypassed that would
        // bypass the duplicate guard with it.
        $opening = collect($response->json('data.types'))->firstWhere('value', 'opening');

        $this->assertFalse($opening['accepts_payment_split']);
        $this->assertFalse($opening['requires_party']);
        $this->assertTrue($opening['moves_stock']);
        $this->assertSame(
            ['draft', 'posted', 'reversed'],
            collect($response->json('data.statuses'))->pluck('value')->all(),
        );
    }

    #[Test]
    public function transactions_are_tenant_scoped(): void
    {
        $other = Tenant::factory()->create();
        $theirs = $this->postSimpleJournal($other, SystemAccount::Cash, SystemAccount::Sales, '999.00');

        // 404 rather than 403: a 403 would confirm the id exists.
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/transactions/{$theirs->id}")
            ->assertNotFound();

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------------------------------------------------------------------
     | The ledger endpoints
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_trial_balance_endpoint_reconciles(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '5000.00');
        $this->postSimpleJournal($this->tenant, SystemAccount::MiscExpense, SystemAccount::Cash, '1200.00');

        $response = $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/ledger/trial-balance')
            ->assertOk()
            ->assertJsonPath('meta.is_balanced', true)
            ->assertJsonPath('meta.difference', '0.00')
            ->assertJsonPath('meta.totals.debit', '6200.00')
            ->assertJsonPath('meta.totals.credit', '6200.00');

        $rows = collect($response->json('data'))->keyBy('account.code');

        $this->assertSame('3800.00', $rows['1010']['balance']);
        $this->assertSame('debit', $rows['1010']['balance_side']);
        $this->assertSame('credit', $rows['4000']['balance_side']);
    }

    #[Test]
    public function an_untouched_workshops_trial_balance_is_zero_not_an_error(): void
    {
        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/ledger/trial-balance')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.is_balanced', true)
            ->assertJsonPath('meta.totals.debit', '0.00');
    }

    #[Test]
    public function an_account_ledger_returns_its_entries_with_a_running_balance(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00', date: '2026-06-01');
        $this->postSimpleJournal($this->tenant, SystemAccount::MiscExpense, SystemAccount::Cash, '250.00', date: '2026-06-05');

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson('/api/v1/ledger/accounts/'.$this->accountId(SystemAccount::Cash))
            ->assertOk()
            ->assertJsonPath('meta.account.name', 'Cash in Hand')
            ->assertJsonPath('meta.opening_balance', '0.00')
            ->assertJsonPath('meta.closing_balance', '750.00')
            ->assertJsonPath('data.0.running_balance', '1000.00')
            ->assertJsonPath('data.1.running_balance', '750.00')
            ->assertJsonPath('data.1.side', 'credit');
    }

    #[Test]
    public function another_workshops_ledger_is_not_reachable_by_id(): void
    {
        $other = Tenant::factory()->create();
        $theirCash = $this->accountFor($other, SystemAccount::Cash);

        $this->withHeaders($this->authHeader($this->owner))
            ->getJson("/api/v1/ledger/accounts/{$theirCash->id}")
            ->assertNotFound();
    }

    /* ---------------------------------------------------------------------
     | Permissions
     |-------------------------------------------------------------------- */

    #[Test]
    public function capturing_transactions_and_reading_the_books_are_separate_authorities(): void
    {
        // The DATA_ENTRY shape: full authority over transactions, no LEDGER.
        [, $clerk] = $this->tenantWithUser([
            ['READ', 'TRANSACTIONS'], ['WRITE', 'TRANSACTIONS'],
        ]);

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/transactions')
            ->assertOk();

        $this->withHeaders($this->authHeader($clerk))
            ->getJson('/api/v1/ledger/trial-balance')
            ->assertForbidden();
    }

    #[Test]
    public function reading_the_books_does_not_confer_the_right_to_write_to_them(): void
    {
        [$readerTenant, $reader] = $this->tenantWithUser([['READ', 'TRANSACTIONS'], ['READ', 'LEDGER']]);

        $cash = $this->accountFor($readerTenant, SystemAccount::Cash);
        $sales = $this->accountFor($readerTenant, SystemAccount::Sales);

        $this->withHeaders($this->authHeader($reader))
            ->postJson('/api/v1/transactions/journal', [
                'date' => now()->toDateString(),
                'post' => true,
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => '100.00'],
                    ['account_id' => $sales->id, 'credit' => '100.00'],
                ],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_platform_admin_gets_a_clear_403_rather_than_a_server_error(): void
    {
        // A wildcard grant passes the permission guard, so this caller reaches
        // the controller — and then has no books. Authority is not membership.
        $platformAdmin = User::factory()->withRole($this->adminRole())->create();

        $this->withHeaders($this->authHeader($platformAdmin))
            ->getJson('/api/v1/transactions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_WORKSPACE');

        $this->withHeaders($this->authHeader($platformAdmin))
            ->getJson('/api/v1/ledger/trial-balance')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'NO_WORKSPACE');
    }

    #[Test]
    public function the_seeded_roles_carry_the_grants_this_module_needs(): void
    {
        $this->seedRoleCatalogue();

        $owner = Role::where('slug', Role::slugFor('OWNER'))->firstOrFail();
        $dataEntry = Role::where('slug', Role::slugFor('DATA_ENTRY'))->firstOrFail();

        $grants = fn ($role) => $role->permissions->map(fn ($p) => "{$p->action}:{$p->resource}")->all();

        $this->assertContains('READ:LEDGER', $grants($owner));
        $this->assertContains('WRITE:TRANSACTIONS', $grants($owner));

        $this->assertContains('WRITE:TRANSACTIONS', $grants($dataEntry));
        // Capturing the day's events is not the same authority as reading the
        // workshop's whole financial position.
        $this->assertNotContains('READ:LEDGER', $grants($dataEntry));
    }

    /* ---------------------------------------------------------------------
     | Books-open date, end to end
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_back_dated_transaction_is_refused_with_an_explanation(): void
    {
        $this->tenant->update(['books_start_date' => '2026-04-01']);

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(date: '2026-03-15'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BOOKS_CLOSED')
            ->assertJsonPath('error.details.books_start_date', '2026-04-01');
    }

    #[Test]
    public function an_archived_account_cannot_be_posted_to(): void
    {
        $archived = $this->actingForTenant($this->tenant, fn () => ChartOfAccount::factory()
            ->ofType(AccountType::Expense)
            ->archived()
            ->create(['code' => '5800', 'name' => 'Old Expense']));

        $this->withHeaders($this->authHeader($this->owner))
            ->postJson('/api/v1/transactions/journal', $this->journal(lines: [
                ['account_id' => $archived->id, 'debit' => '100.00'],
                ['account_id' => $this->accountId(SystemAccount::Cash), 'credit' => '100.00'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'JOURNAL_ACCOUNT_ARCHIVED');
    }
}
