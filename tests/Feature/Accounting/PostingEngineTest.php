<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\BooksClosedException;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Accounting\LedgerImmutableException;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Exceptions\Accounting\UnbalancedJournalException;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\Posting\PostingBatch;
use App\Services\Accounting\Posting\PostingLine;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithAuthModule;
use Tests\Concerns\InteractsWithLedger;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The invariants of the posting engine, one test each.
 *
 * Every module below M4 inherits whatever is true here, and a bug in this class
 * is invisible for months and then unrecoverable — so this file is deliberately
 * the most thorough in the product.
 */
class PostingEngineTest extends TestCase
{
    use InteractsWithAuthModule, InteractsWithLedger, InteractsWithTenancy, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->owner] = $this->tenantWithUser([['*', '*']]);
    }

    /* ---------------------------------------------------------------------
     | Debits equal credits, or nothing posts
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_balanced_journal_reaches_both_account_ledgers(): void
    {
        $transaction = $this->postSimpleJournal(
            $this->tenant,
            SystemAccount::Cash,
            SystemAccount::Sales,
            '5000.00',
            notes: 'Counter sale',
        );

        $this->assertSame(TransactionStatus::Posted, $transaction->status);
        $this->assertSame('5000.00', $transaction->total);
        $this->assertCount(2, $transaction->entries);
        $this->assertNotNull($transaction->posted_at);

        // Cash is debit-normal and Sales credit-normal, so both balances are
        // positive: the workshop holds ₹5,000 and has earned ₹5,000.
        $this->assertSame('5000.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('5000.00', $this->balanceOf($this->tenant, SystemAccount::Sales));

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_unbalanced_journal_is_refused_and_writes_nothing(): void
    {
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '5000.00'],
            [SystemAccount::Sales, 'credit', '4999.99'],
        ]));

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
            $this->fail('An unbalanced journal was accepted.');
        } catch (UnbalancedJournalException $e) {
            $this->assertSame('JOURNAL_UNBALANCED', $e->errorCode());
            $this->assertSame('0.01', $e->details()['difference']);
        }

        // Nothing at all: not the transaction, not one of its lines.
        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::count()));
        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));
    }

    #[Test]
    public function a_paisa_sized_difference_is_still_a_refusal(): void
    {
        // Rounding is where an unbalanced ledger usually comes from, and one
        // paisa a day compounds into a reconciliation nobody can unpick.
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '0.10'],
            [SystemAccount::Sales, 'credit', '0.11'],
        ]));

        $this->expectException(UnbalancedJournalException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
    }

    #[Test]
    public function a_journal_whose_lines_only_balance_in_decimal_arithmetic_posts(): void
    {
        // 0.10 + 0.20 against 0.30. In floating point the two sides differ by
        // 5.5e-17 and a naive engine refuses a correct entry.
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '0.10'],
            [SystemAccount::Bank, 'debit', '0.20'],
            [SystemAccount::Sales, 'credit', '0.30'],
        ]));

        $transaction = $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));

        $this->assertSame('0.30', $transaction->total);
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_transaction_with_fewer_than_two_lines_is_refused(): void
    {
        $cash = $this->accountFor($this->tenant, SystemAccount::Cash);

        $batch = PostingBatch::of(
            type: TransactionType::Journal,
            date: now()->toDateString(),
            lines: [PostingLine::debit($cash->id, Money::of('100.00'))],
        );

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
            $this->fail('A single-line journal was accepted.');
        } catch (InvalidJournalException $e) {
            $this->assertSame('JOURNAL_TOO_FEW_LINES', $e->errorCode());
        }

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::count()));
    }

    #[Test]
    public function a_line_must_be_a_debit_or_a_credit_and_not_both(): void
    {
        $cash = $this->accountFor($this->tenant, SystemAccount::Cash);

        foreach ([
            ['debit' => '100.00', 'credit' => '100.00'],
            ['debit' => '0', 'credit' => '0'],
            [],
        ] as $line) {
            try {
                PostingLine::fromInput($line + ['account_id' => $cash->id], 1);
                $this->fail('A line with an ambiguous side was accepted.');
            } catch (InvalidJournalException $e) {
                $this->assertSame('JOURNAL_LINE_INVALID', $e->errorCode());
            }
        }
    }

    #[Test]
    public function a_zero_amount_line_is_refused(): void
    {
        $batch = PostingBatch::of(
            type: TransactionType::Journal,
            date: now()->toDateString(),
            lines: [
                PostingLine::debit($this->accountFor($this->tenant, SystemAccount::Cash)->id, Money::zero()),
                PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Sales)->id, Money::zero()),
            ],
        );

        $this->expectException(InvalidJournalException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
    }

    #[Test]
    public function a_negative_amount_is_refused_rather_than_flipped(): void
    {
        // The side already carries the direction, so every stored amount is
        // positive — a negative debit is a credit written confusingly, and
        // guessing which the author meant is not the engine's job.
        $batch = PostingBatch::of(
            type: TransactionType::Journal,
            date: now()->toDateString(),
            lines: [
                PostingLine::debit($this->accountFor($this->tenant, SystemAccount::Cash)->id, Money::of('-100.00')),
                PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Sales)->id, Money::of('-100.00')),
            ],
        );

        $this->expectException(InvalidJournalException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
    }

    /* ---------------------------------------------------------------------
     | Accounts
     |-------------------------------------------------------------------- */

    #[Test]
    public function it_refuses_an_account_from_another_workshop(): void
    {
        $otherTenant = Tenant::factory()->create();
        $theirCash = $this->accountFor($otherTenant, SystemAccount::Cash);

        $batch = PostingBatch::of(
            type: TransactionType::Journal,
            date: now()->toDateString(),
            lines: [
                PostingLine::debit($theirCash->id, Money::of('100.00')),
                PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Sales)->id, Money::of('100.00')),
            ],
        );

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
            $this->fail("Another workshop's account was accepted.");
        } catch (InvalidJournalException $e) {
            // The tenant scope means a foreign id simply does not resolve —
            // isolation is structural here rather than a check someone wrote.
            $this->assertSame('JOURNAL_ACCOUNT_UNKNOWN', $e->errorCode());
        }

        $this->assertSame(0, $this->actingForTenant($otherTenant, fn () => JournalEntry::count()));
    }

    #[Test]
    public function it_refuses_an_archived_account(): void
    {
        $archived = $this->actingForTenant($this->tenant, fn () => ChartOfAccount::factory()
            ->ofType(AccountType::Expense)
            ->archived()
            ->create(['code' => '5600', 'name' => 'Discontinued Expense']));

        $batch = PostingBatch::of(
            type: TransactionType::Journal,
            date: now()->toDateString(),
            lines: [
                PostingLine::debit($archived->id, Money::of('100.00')),
                PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Cash)->id, Money::of('100.00')),
            ],
        );

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
            $this->fail('An archived account was posted to.');
        } catch (InvalidJournalException $e) {
            $this->assertSame('JOURNAL_ACCOUNT_ARCHIVED', $e->errorCode());
        }
    }

    /* ---------------------------------------------------------------------
     | The books-open date, carried over from M2.2
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_transaction_dated_before_go_live_is_refused(): void
    {
        $this->tenant->update(['books_start_date' => '2026-04-01']);

        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor(
            $this->tenant,
            [
                [SystemAccount::Cash, 'debit', '100.00'],
                [SystemAccount::Sales, 'credit', '100.00'],
            ],
            date: '2026-03-31',
        ));

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));
            $this->fail('A back-dated transaction was accepted.');
        } catch (BooksClosedException $e) {
            $this->assertSame('BOOKS_CLOSED', $e->errorCode());
            $this->assertSame('2026-04-01', $e->details()['books_start_date']);
        }

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));
    }

    #[Test]
    public function a_transaction_dated_on_go_live_day_posts(): void
    {
        $this->tenant->update(['books_start_date' => '2026-04-01']);

        $transaction = $this->postSimpleJournal(
            $this->tenant,
            SystemAccount::Cash,
            SystemAccount::Sales,
            '100.00',
            date: '2026-04-01',
        );

        $this->assertTrue($transaction->isPosted());
    }

    #[Test]
    public function a_back_dated_draft_is_refused_too(): void
    {
        // Otherwise a workshop could save a draft it can never post, which is
        // a worse experience than being told at the point of writing it.
        $this->tenant->update(['books_start_date' => '2026-04-01']);

        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor(
            $this->tenant,
            [
                [SystemAccount::Cash, 'debit', '100.00'],
                [SystemAccount::Sales, 'credit', '100.00'],
            ],
            date: '2026-03-01',
        ));

        $this->expectException(BooksClosedException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->draft($batch));
    }

    /* ---------------------------------------------------------------------
     | Drafts
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_draft_posts_nothing_until_it_is_authorised(): void
    {
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '750.00'],
            [SystemAccount::ServiceIncome, 'credit', '750.00'],
        ]));

        $draft = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft($batch, $this->owner));

        $this->assertTrue($draft->isDraft());
        $this->assertCount(2, $draft->draft_lines);

        // The ledger is untouched, and so is every balance derived from it.
        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertBooksBalance($this->tenant, 'while a draft is outstanding');

        $posted = $this->actingForTenant($this->tenant, fn () => $this->engine()->postDraft($draft->fresh(), $this->owner));

        $this->assertTrue($posted->isPosted());
        // The draft's own row became the posted transaction, so a reference
        // held elsewhere still points at the right thing.
        $this->assertSame($draft->id, $posted->id);
        // Intent is cleared at the moment it becomes fact — the two can never
        // both exist and disagree.
        $this->assertNull($posted->draft_lines);
        $this->assertSame('750.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function an_unbalanced_draft_may_be_saved_but_never_posted(): void
    {
        // A half-written voucher is work in progress. Refusing to save it would
        // only push people into posting before they are ready.
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '500.00'],
            [SystemAccount::Sales, 'credit', '300.00'],
        ]));

        $draft = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft($batch));

        $this->assertTrue($draft->isDraft());

        $this->expectException(UnbalancedJournalException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->postDraft($draft->fresh()));
    }

    #[Test]
    public function a_stale_draft_is_revalidated_against_todays_chart_before_it_posts(): void
    {
        $spare = $this->actingForTenant($this->tenant, fn () => ChartOfAccount::factory()
            ->ofType(AccountType::Expense)
            ->create(['code' => '5700', 'name' => 'Sundry Costs']));

        $batch = PostingBatch::of(
            type: TransactionType::Journal,
            date: now()->toDateString(),
            lines: [
                PostingLine::debit($spare->id, Money::of('200.00')),
                PostingLine::credit($this->accountFor($this->tenant, SystemAccount::Cash)->id, Money::of('200.00')),
            ],
        );

        $draft = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft($batch));

        // The account is archived after the draft was written — the situation
        // M15's parked drafts make routine.
        $this->actingForTenant($this->tenant, fn () => $spare->update(['is_active' => false]));

        $this->expectException(InvalidJournalException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->postDraft($draft->fresh()));
    }

    #[Test]
    public function only_a_draft_can_be_posted(): void
    {
        $posted = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->postDraft($posted));
            $this->fail('A posted transaction was posted a second time.');
        } catch (TransactionImmutableException $e) {
            $this->assertSame('TRANSACTION_NOT_A_DRAFT', $e->errorCode());
        }

        // Above all, it did not write its lines twice.
        $this->assertSame(2, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));
    }

    /* ---------------------------------------------------------------------
     | Immutability
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_posted_transaction_cannot_be_edited(): void
    {
        $transaction = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        foreach (['date' => '2026-01-01', 'total' => '999.00', 'notes' => 'Tampered'] as $field => $value) {
            try {
                $this->actingForTenant($this->tenant, fn () => $transaction->fresh()->update([$field => $value]));
                $this->fail("A posted transaction's [{$field}] was changed.");
            } catch (TransactionImmutableException $e) {
                $this->assertSame('TRANSACTION_IMMUTABLE', $e->errorCode());
            }
        }
    }

    #[Test]
    public function a_posted_transaction_cannot_be_deleted(): void
    {
        $transaction = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        $this->expectException(TransactionImmutableException::class);

        $this->actingForTenant($this->tenant, fn () => $transaction->delete());
    }

    #[Test]
    public function a_journal_entry_can_never_be_updated_or_deleted(): void
    {
        $transaction = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        /** @var JournalEntry $entry */
        $entry = $transaction->entries->first();

        try {
            $this->actingForTenant($this->tenant, fn () => $entry->update(['debit' => '1.00']));
            $this->fail('A ledger entry was modified.');
        } catch (LedgerImmutableException $e) {
            $this->assertSame('LEDGER_IMMUTABLE', $e->errorCode());
        }

        $this->expectException(LedgerImmutableException::class);

        $this->actingForTenant($this->tenant, fn () => $entry->delete());
    }

    #[Test]
    public function a_draft_can_be_edited_and_discarded_freely(): void
    {
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '100.00'],
            [SystemAccount::Sales, 'credit', '100.00'],
        ]));

        $draft = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft($batch));

        $revised = $this->actingForTenant($this->tenant, fn () => $this->engine()->updateDraft(
            $draft,
            $this->batchFor($this->tenant, [
                [SystemAccount::Bank, 'debit', '250.00'],
                [SystemAccount::Sales, 'credit', '250.00'],
            ]),
        ));

        $this->assertSame('250.00', $revised->total);

        $this->actingForTenant($this->tenant, fn () => $revised->delete());

        $this->assertSame(0, $this->actingForTenant($this->tenant, fn () => Transaction::count()));
    }

    /* ---------------------------------------------------------------------
     | Reversal — the only correction there is
     |-------------------------------------------------------------------- */

    #[Test]
    public function a_reversal_cancels_the_original_and_keeps_both(): void
    {
        $original = $this->postSimpleJournal(
            $this->tenant,
            SystemAccount::Cash,
            SystemAccount::Sales,
            '1200.00',
            notes: 'Mis-keyed sale',
        );

        $reversal = $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse(
            $original,
            null,
            'Wrong customer',
            $this->owner,
        ));

        $this->assertSame($original->id, $reversal->reverses_id);
        $this->assertSame('Wrong customer', $reversal->notes);
        $this->assertTrue($reversal->isPosted());

        // The original keeps every one of its entries — history is corrected
        // by addition, never by erasure.
        $refreshed = $this->actingForTenant($this->tenant, fn () => $original->fresh()->load('entries'));

        $this->assertSame(TransactionStatus::Reversed, $refreshed->status);
        $this->assertCount(2, $refreshed->entries);
        $this->assertSame(4, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));

        // And the net effect on every account is nil.
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('0.00', $this->balanceOf($this->tenant, SystemAccount::Sales));
        $this->assertBooksBalance($this->tenant, 'after a reversal');
    }

    #[Test]
    public function a_reversal_mirrors_every_line_of_a_multi_line_transaction(): void
    {
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Receivables, 'debit', '11800.00'],
            [SystemAccount::Sales, 'credit', '10000.00'],
            [SystemAccount::GstOutput, 'credit', '1800.00'],
        ]));

        $original = $this->actingForTenant($this->tenant, fn () => $this->engine()->post($batch));

        $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($original));

        foreach ([SystemAccount::Receivables, SystemAccount::Sales, SystemAccount::GstOutput] as $account) {
            $this->assertSame('0.00', $this->balanceOf($this->tenant, $account));
        }

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_transaction_cannot_be_reversed_twice(): void
    {
        $original = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');

        $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($original));

        try {
            $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($original->fresh()));
            $this->fail('A transaction was reversed twice.');
        } catch (TransactionImmutableException $e) {
            $this->assertSame('TRANSACTION_ALREADY_REVERSED', $e->errorCode());
        }

        $this->assertBooksBalance($this->tenant);
    }

    #[Test]
    public function a_draft_is_discarded_rather_than_reversed(): void
    {
        $batch = $this->actingForTenant($this->tenant, fn () => $this->batchFor($this->tenant, [
            [SystemAccount::Cash, 'debit', '100.00'],
            [SystemAccount::Sales, 'credit', '100.00'],
        ]));

        $draft = $this->actingForTenant($this->tenant, fn () => $this->engine()->draft($batch));

        $this->expectException(TransactionImmutableException::class);

        $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse($draft));
    }

    #[Test]
    public function a_reversal_may_be_dated_into_the_period_being_corrected(): void
    {
        $original = $this->postSimpleJournal(
            $this->tenant,
            SystemAccount::Cash,
            SystemAccount::Sales,
            '400.00',
            date: '2026-05-10',
        );

        $reversal = $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse(
            $original,
            new \DateTimeImmutable('2026-05-31'),
        ));

        $this->assertSame('2026-05-31', $reversal->date->toDateString());
        // Both sit inside May, so a May report shows the pair netting to nil
        // rather than a stray income figure the following month.
        $this->assertSame('0.00', $this->actingForTenant(
            $this->tenant,
            fn () => $this->ledger()->balanceFor(
                $this->accountFor($this->tenant, SystemAccount::Sales),
                '2026-05-01',
                '2026-05-31',
            )->amount()
        ));
    }

    #[Test]
    public function the_database_itself_refuses_a_line_on_both_sides_or_neither(): void
    {
        // The one place a raw query is legitimate: proving that the CHECK
        // constraints hold even for something that never went through the
        // engine — a future import script, a migration, a mistake. The
        // application always goes through Eloquent, or the tenant scope would
        // not apply.
        $transaction = $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');
        $cash = $this->accountFor($this->tenant, SystemAccount::Cash);

        foreach ([
            ['debit' => '10.00', 'credit' => '10.00'],   // both sides
            ['debit' => '0.00', 'credit' => '0.00'],     // neither
            ['debit' => '-10.00', 'credit' => '0.00'],   // negative
        ] as $index => $amounts) {
            try {
                DB::table('journal_entries')->insert([
                    'tenant_id' => $this->tenant->id,
                    'transaction_id' => $transaction->id,
                    'account_id' => $cash->id,
                    'line_no' => 90 + $index,
                    'debit' => $amounts['debit'],
                    'credit' => $amounts['credit'],
                    'date' => '2026-07-01',
                ]);

                $this->fail('The database accepted a malformed ledger line: '.json_encode($amounts));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertBooksBalance($this->tenant);
    }

    /* ---------------------------------------------------------------------
     | Tenancy
     |-------------------------------------------------------------------- */

    #[Test]
    public function entries_are_stamped_with_the_posting_workshop(): void
    {
        $other = Tenant::factory()->create();

        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '100.00');
        $this->postSimpleJournal($other, SystemAccount::Cash, SystemAccount::Sales, '250.00');

        $this->assertSame(2, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));
        $this->assertSame(2, $this->actingForTenant($other, fn () => JournalEntry::count()));

        // Neither workshop's numbers show up in the other's books.
        $this->assertSame('100.00', $this->balanceOf($this->tenant, SystemAccount::Cash));
        $this->assertSame('250.00', $this->balanceOf($other, SystemAccount::Cash));

        $this->assertBooksBalance($this->tenant);
        $this->assertBooksBalance($other);
    }

    /* ---------------------------------------------------------------------
     | Volume
     |-------------------------------------------------------------------- */

    #[Test]
    public function the_books_still_reconcile_after_a_long_run_of_mixed_postings(): void
    {
        $amounts = ['0.01', '33.33', '1999.99', '10.10', '0.05', '87654.32'];

        foreach ($amounts as $index => $amount) {
            $this->postSimpleJournal(
                $this->tenant,
                $index % 2 === 0 ? SystemAccount::Cash : SystemAccount::Bank,
                $index % 3 === 0 ? SystemAccount::Sales : SystemAccount::ServiceIncome,
                $amount,
                date: '2026-06-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            );
        }

        // Three-line entries too, so the invariant is not only proven on pairs.
        $this->actingForTenant($this->tenant, fn () => $this->engine()->post(
            $this->batchFor($this->tenant, [
                [SystemAccount::Receivables, 'debit', '11800.00'],
                [SystemAccount::Sales, 'credit', '10000.00'],
                [SystemAccount::GstOutput, 'credit', '1800.00'],
            ])
        ));

        // And a reversal in the middle of it all.
        $this->actingForTenant($this->tenant, fn () => $this->engine()->reverse(
            Transaction::query()->inTheBooks()->orderBy('id')->first()
        ));

        $this->assertBooksBalance($this->tenant, 'after a run of mixed postings');

        $totals = $this->actingForTenant($this->tenant, fn () => $this->ledger()->totals());
        $this->assertTrue($totals['is_balanced']);
    }

    #[Test]
    public function interleaved_postings_leave_the_books_balanced(): void
    {
        // Real concurrency cannot be simulated in one process, and it does not
        // need to be: each posting is internally balanced and commits in a
        // single database transaction, so whatever order they interleave in,
        // the total of a set of balanced sets is balanced. What this asserts is
        // that no posting leaks state into the next — the engine holds nothing
        // between calls, and the tenant it writes for comes from the context
        // each time.
        $second = Tenant::factory()->create();

        for ($i = 1; $i <= 10; $i++) {
            $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, "{$i}.99");
            $this->postSimpleJournal($second, SystemAccount::Bank, SystemAccount::ServiceIncome, "{$i}.01");
        }

        $this->assertBooksBalance($this->tenant);
        $this->assertBooksBalance($second);

        $this->assertSame(20, $this->actingForTenant($this->tenant, fn () => JournalEntry::count()));
        $this->assertSame(20, $this->actingForTenant($second, fn () => JournalEntry::count()));
    }

    /* ---------------------------------------------------------------------
     | No stored balances
     |-------------------------------------------------------------------- */

    #[Test]
    public function an_account_balance_is_always_the_sum_of_its_entries(): void
    {
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::Sales, '1000.00');
        $this->postSimpleJournal($this->tenant, SystemAccount::Cash, SystemAccount::ServiceIncome, '250.50');
        $this->postSimpleJournal($this->tenant, SystemAccount::MiscExpense, SystemAccount::Cash, '99.50');

        $cash = $this->accountFor($this->tenant, SystemAccount::Cash);

        $summed = $this->actingForTenant($this->tenant, fn () => Money::sum(
            JournalEntry::forAccount($cash->id)->get()->map(
                fn (JournalEntry $entry) => $entry->signedAgainst($cash->normalBalance())
            )
        ));

        $this->assertSame('1151.00', $summed->amount());
        $this->assertSame($summed->amount(), $this->balanceOf($this->tenant, SystemAccount::Cash));

        // And nothing anywhere stores it: the chart of accounts has no balance
        // column at all, which is what makes drift impossible rather than
        // merely unlikely.
        $this->assertNotContains('balance', array_keys($cash->getAttributes()));
    }
}
