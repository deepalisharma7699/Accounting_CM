<?php

namespace Tests\Concerns;

use App\Enums\BalanceSide;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Models\ChartOfAccount;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerService;
use App\Services\Accounting\PartyLedgerService;
use App\Services\Accounting\Posting\PostingBatch;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\PostingEngine;
use App\Support\Money;
use Closure;

/**
 * Helpers for anything that touches the books.
 *
 * The important one is {@see assertBooksBalance()}. Every module from M4
 * onwards must leave the trial balance reconciling after every scenario, so it
 * is one assertion rather than something each test re-derives — and it asserts
 * the accounting identity twice over, on gross movements and on net balances,
 * because a single compensating pair of errors can satisfy one and not the
 * other.
 *
 * Use alongside {@see InteractsWithTenancy}, which supplies actingForTenant().
 */
trait InteractsWithLedger
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    abstract protected function actingForTenant(Tenant|int|null $tenant, Closure $callback): mixed;

    protected function engine(): PostingEngine
    {
        return app(PostingEngine::class);
    }

    protected function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    /**
     * The tenant's copy of a seeded account. Resolved by system key, never by
     * name or id — the same way the engine reaches it.
     */
    protected function accountFor(Tenant $tenant, SystemAccount $key): ChartOfAccount
    {
        return $this->actingForTenant(
            $tenant,
            fn () => ChartOfAccount::where('system_key', $key->value)->firstOrFail()
        );
    }

    /**
     * Post a balanced journal: debit one account, credit another.
     *
     * The common shape by far, and writing it out in every test buries the
     * thing each test is actually about.
     */
    protected function postSimpleJournal(
        Tenant $tenant,
        SystemAccount $debit,
        SystemAccount $credit,
        string $amount,
        ?string $date = null,
        ?string $notes = null,
        ?User $actor = null,
        Party|int|null $party = null,
    ): Transaction {
        return $this->actingForTenant($tenant, fn () => $this->engine()->post(
            PostingBatch::of(
                type: TransactionType::Journal,
                date: $date ?? now()->toDateString(),
                lines: [
                    PostingLine::debit($this->accountFor($tenant, $debit)->id, Money::of($amount)),
                    PostingLine::credit($this->accountFor($tenant, $credit)->id, Money::of($amount)),
                ],
                notes: $notes,
                partyId: $party instanceof Party ? $party->id : $party,
            ),
            $actor,
        ));
    }

    /**
     * A batch of raw lines, in the `[[SystemAccount, 'debit'|'credit', amount]]`
     * shape, for the cases a two-line helper cannot express.
     *
     * @param  array<int, array{0: SystemAccount, 1: string, 2: string}>  $lines
     * @param  Party|int|null  $party  The counterparty, for anything exercising
     *                                 a party ledger.
     */
    protected function batchFor(
        Tenant $tenant,
        array $lines,
        ?string $date = null,
        ?string $notes = null,
        Party|int|null $party = null,
    ): PostingBatch {
        return PostingBatch::of(
            type: TransactionType::Journal,
            date: $date ?? now()->toDateString(),
            lines: array_map(fn (array $line) => PostingLine::on(
                $line[1] === 'debit' ? BalanceSide::Debit : BalanceSide::Credit,
                $this->accountFor($tenant, $line[0])->id,
                Money::of($line[2]),
            ), $lines),
            notes: $notes,
            partyId: $party instanceof Party ? $party->id : $party,
        );
    }

    /**
     * Post a settlement — a payment out or a receipt in — through its own
     * template, the way the application does.
     *
     * The split is given as `[['cash', '2000.00'], ['upi', '3000.00', 'ref']]`,
     * which is the shape a test wants to read: the modes and the amounts, with
     * the reference only where it matters.
     *
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $split
     */
    protected function postSettlement(
        Tenant $tenant,
        TransactionType $type,
        Party $party,
        array $split,
        ?string $date = null,
        ?string $notes = null,
        ?User $actor = null,
        bool $post = true,
    ): Transaction {
        return $this->actingForTenant($tenant, function () use ($type, $party, $split, $date, $notes, $actor, $post) {
            $batch = $this->engine()->compose($type, [
                'date' => $date ?? now()->toDateString(),
                'notes' => $notes,
                'party_id' => $party->id,
                'payments' => array_map(fn (array $line) => [
                    'mode' => $line[0],
                    'amount' => $line[1],
                    'reference' => $line[2] ?? null,
                ], $split),
            ]);

            return $post
                ? $this->engine()->post($batch, $actor)
                : $this->engine()->draft($batch, $actor);
        });
    }

    /**
     * Money paid out to a supplier — template D.
     *
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $split
     */
    protected function payVendor(Tenant $tenant, Party $party, array $split, ?string $date = null, bool $post = true): Transaction
    {
        return $this->postSettlement($tenant, TransactionType::Payment, $party, $split, $date, post: $post);
    }

    /**
     * Money collected from a customer — template E.
     *
     * @param  array<int, array{0: string, 1: string, 2?: string|null}>  $split
     */
    protected function receiveFrom(Tenant $tenant, Party $party, array $split, ?string $date = null, bool $post = true): Transaction
    {
        return $this->postSettlement($tenant, TransactionType::Receipt, $party, $split, $date, post: $post);
    }

    /**
     * A party's outstanding position as three decimal strings, so an assertion
     * reads `['5000.00', '0.00', '5000.00']` rather than comparing objects.
     *
     * @return array{receivable: string, payable: string, net: string}
     */
    protected function positionOf(Tenant $tenant, Party $party): array
    {
        $position = $this->actingForTenant(
            $tenant,
            fn () => app(PartyLedgerService::class)->positionFor($party)
        );

        return [
            'receivable' => $position['receivable']->amount(),
            'payable' => $position['payable']->amount(),
            'net' => $position['net']->amount(),
        ];
    }

    /**
     * The invariant, asserted: debits equal credits, both as gross movements
     * and after every account is collapsed onto one side.
     */
    protected function assertBooksBalance(Tenant $tenant, string $because = ''): void
    {
        $trial = $this->actingForTenant($tenant, fn () => $this->ledger()->trialBalance());

        $this->assertTrue(
            $trial['totals']['debit']->equals($trial['totals']['credit']),
            sprintf(
                'The trial balance does not reconcile%s: debits %s, credits %s.',
                $because === '' ? '' : " {$because}",
                $trial['totals']['debit']->amount(),
                $trial['totals']['credit']->amount(),
            ),
        );

        $this->assertTrue(
            $trial['balances']['debit']->equals($trial['balances']['credit']),
            sprintf(
                'Net account balances do not reconcile%s: debit side %s, credit side %s.',
                $because === '' ? '' : " {$because}",
                $trial['balances']['debit']->amount(),
                $trial['balances']['credit']->amount(),
            ),
        );

        $this->assertTrue($trial['is_balanced']);
    }

    /**
     * An account's balance on its normal side, as a decimal string — so an
     * assertion reads `'5000.00'` rather than comparing objects.
     */
    protected function balanceOf(Tenant $tenant, SystemAccount $key): string
    {
        return $this->actingForTenant(
            $tenant,
            fn () => $this->ledger()->balanceFor($this->accountFor($tenant, $key))->amount()
        );
    }
}
