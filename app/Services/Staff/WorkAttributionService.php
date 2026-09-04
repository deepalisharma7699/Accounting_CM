<?php

namespace App\Services\Staff;

use App\Enums\TransactionType;
use App\Exceptions\Staff\InvalidAttributionException;
use App\Models\Transaction;
use App\Models\TransactionStaff;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\StaffDesignationRepositoryInterface;
use App\Repositories\Contracts\TransactionStaffRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Who did the work an invoice was raised for — M22.
 *
 * "Ramesh fitted it, Sunil wound it", recorded on the sale that billed it, so a
 * workshop can answer the two questions it actually asks: which of my people did
 * this motor, and how much has this person got through this month.
 *
 * ## Why the roster is served from here and not from `/staff`
 *
 * Because the counter clerk who raises the invoice does not hold `READ:STAFF`,
 * and must not. Only OWNER holds it — see {@see \App\Enums\PermissionResource::Staff},
 * which says why in as many words: what each person in a workshop earns is not
 * something the clerk at the counter needs in order to do their job. Fetching
 * the pickers from `/staff` would either 403 the sale form for its main user or
 * force that grant onto everybody who writes an invoice, and the second is how a
 * permission model quietly stops meaning anything.
 *
 * So {@see slots()} publishes **names and nothing else** — no rate, no basis, no
 * joining date, no phone number — under the transactions grant, and the pay
 * stays where it was. This is the line CLAUDE.md already draws for a party's
 * outstanding: between one fact somebody needs to do their job and the records
 * behind it, never between the name and the money.
 *
 * ## Why sync is whole-document
 *
 * Because clearing a picker has to be able to remove a row. An API that only
 * ever added would make a mis-picked name permanent on a posted invoice, which
 * is the failure this whole feature is here to avoid — see the create migration
 * for why reversing and reissuing a sale is not a way out of it.
 */
class WorkAttributionService
{
    public function __construct(
        private readonly TransactionStaffRepositoryInterface $attributions,
        private readonly StaffDesignationRepositoryInterface $designations,
        private readonly EmployeeRepositoryInterface $employees,
    ) {}

    /* ---------------------------------------------------------------------
     | What the sale form draws
     |-------------------------------------------------------------------- */

    /**
     * The pickers a sale should paint, each with the people who can fill it.
     *
     * One entry per designation the workshop ticked, and the roster is repeated
     * against each rather than sent once beside them. That is a few hundred
     * bytes of duplication and it buys the thing that matters: a form that
     * renders every picker from its own slot and holds no opinion about which
     * trades exist. The alternative — a shared list plus a rule about which
     * names belong to which box — is that opinion, written twice.
     *
     * Empty where the workshop has ticked nothing, and the form then draws
     * nothing at all. A workshop that does not track this never sees it.
     *
     * @return array<int, array{designation_id: int, designation: string, employees: array<int, array{id: int, name: string}>}>
     */
    public function slots(): array
    {
        $designations = $this->designations->trackedOnSales();

        if ($designations->isEmpty()) {
            return [];
        }

        /*
        | Active staff only.
        |
        | Somebody who has left cannot have done work that is being billed today,
        | and offering them is how a leaver quietly goes on collecting credit for
        | months. Correcting an *old* invoice is the one case where a departed
        | name is legitimate, and {@see assertEmployeeExists()} allows it — the
        | correction dialog carries the name it is already showing.
        */
        $roster = $this->employees->all(activeOnly: true)
            ->map(fn ($employee) => [
                'id' => (int) $employee->id,
                'name' => $employee->name,
            ])
            ->values()
            ->all();

        return $designations->map(fn ($designation) => [
            'designation_id' => (int) $designation->id,
            'designation' => $designation->name,
            'employees' => $roster,
        ])->values()->all();
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * Hang each document's attribution on it, for a page of them at once.
     *
     * Set as a plain property rather than through the relation, and deliberately:
     * a transaction is very often read and then saved — posting a draft is
     * exactly that sequence — and this keeps a listing's convenience away from
     * anything Eloquent will try to write back.
     *
     * @param  Collection<int, Transaction>|array<int, Transaction>  $transactions
     */
    public function attachTo(Collection|array $transactions): void
    {
        $records = $transactions instanceof Collection ? $transactions : collect($transactions);

        // Sales only. Nothing else can carry one, and asking about a page of
        // receipts would be a query returning nothing.
        $sales = $records->filter(fn (Transaction $transaction) => $transaction->type === TransactionType::Sale);

        if ($sales->isEmpty()) {
            return;
        }

        $byTransaction = $this->attributions->forTransactions($sales->pluck('id')->all());

        foreach ($sales as $sale) {
            $sale->staffAttribution = $byTransaction[(int) $sale->id] ?? collect();
        }
    }

    /**
     * What one person got through, and the invoices behind it — M22.
     *
     * @return array{job_count: int, invoice_value: string}
     */
    public function workFor(int $employeeId, ?string $from = null, ?string $to = null): array
    {
        return $this->attributions->workSummaryFor($employeeId, $from, $to);
    }

    /**
     * The invoices behind those figures, newest first.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Transaction>
     */
    public function invoicesFor(int $employeeId, ?string $from, ?string $to, int $perPage): LengthAwarePaginator
    {
        return $this->attributions->invoicesFor($employeeId, $from, $to, $perPage);
    }

    /**
     * A page of those invoices, as the staff drawer wants to read them.
     *
     * Its own shape rather than {@see \App\Http\Resources\TransactionResource},
     * and for the reason that resource's own docblock gives about the customer's
     * copy: it carries the cost of every line, the margin and the ledger
     * entries, and none of that is what "how much has Ramesh got through" is
     * asking. Serialising the whole document here would also mean a settlement
     * lookup per row for a list nobody is going to collect against.
     *
     * `trades` is the part that would be missing from a plain invoice list, and
     * it is the part an owner reads first: whether this person fitted it or wound
     * it. Only the trades *they* were credited with — the other name on the
     * document is somebody else's throughput.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $invoices
     * @return array<int, array<string, mixed>>
     */
    public function describeInvoices(int $employeeId, Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $byTransaction = $this->attributions->forTransactions($invoices->pluck('id')->all());

        return $invoices->map(function (Transaction $invoice) use ($employeeId, $byTransaction) {
            $trades = ($byTransaction[(int) $invoice->id] ?? collect())
                ->filter(fn (TransactionStaff $row) => (int) $row->employee_id === $employeeId)
                ->map(fn (TransactionStaff $row) => $row->designation?->name)
                ->filter()
                ->values()
                ->all();

            return [
                'id' => (int) $invoice->id,
                'doc_no' => $invoice->doc_no,
                'date' => $invoice->date->toDateString(),
                'party' => $invoice->party?->name,
                'total' => Money::of($invoice->total)->amount(),
                'trades' => $trades,
            ];
        })->values()->all();
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * Record — or correct — who did the work on this sale.
     *
     * @param  array<int, array{employee_id: mixed, designation_id: mixed}>  $pairs
     * @return Collection<int, TransactionStaff>
     */
    public function sync(Transaction $transaction, array $pairs): Collection
    {
        if ($transaction->type !== TransactionType::Sale) {
            throw InvalidAttributionException::notASale($transaction->type->label());
        }

        $clean = [];

        foreach ($pairs as $pair) {
            $employeeId = $this->intOrNull($pair['employee_id'] ?? null);
            $designationId = $this->intOrNull($pair['designation_id'] ?? null);

            if ($designationId === null) {
                continue;
            }

            /*
            | A slot the operator left empty, or cleared.
            |
            | Sent rather than omitted, and that is the point: "the winder box is
            | empty" and "this client does not know about winders" are different
            | statements, and only the first should remove a row. Skipping it
            | here lets the sync below delete what is no longer named.
            */
            if ($employeeId === null) {
                continue;
            }

            $this->assertDesignationIsAskedFor($transaction, $designationId);
            $this->assertEmployeeExists($employeeId);

            $clean[] = ['employee_id' => $employeeId, 'designation_id' => $designationId];
        }

        $result = $this->attributions->syncFor((int) $transaction->id, $clean);

        Log::info('staff.attribution.synced', [
            'transaction_id' => $transaction->id,
            'trades' => $result->count(),
        ]);

        return $result;
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * The trade has to be one the workshop asks about on a sale.
     *
     * With one exception, and it is the reason this takes the transaction: a
     * designation that is **already on this document** stays editable even after
     * it is archived or un-ticked. Otherwise correcting a name on last quarter's
     * invoice would be refused because the workshop stopped tracking varnishing
     * in the meantime — a refusal about today's configuration, blocking a
     * statement about the past.
     */
    private function assertDesignationIsAskedFor(Transaction $transaction, int $designationId): void
    {
        $tracked = $this->designations->trackedOnSales()
            ->contains(fn ($designation) => (int) $designation->id === $designationId);

        if ($tracked) {
            return;
        }

        $alreadyOnDocument = $this->attributions->forTransaction((int) $transaction->id)
            ->contains(fn (TransactionStaff $row) => (int) $row->designation_id === $designationId);

        if ($alreadyOnDocument) {
            return;
        }

        throw InvalidAttributionException::untrackedDesignation($designationId);
    }

    /**
     * The person has to be on this workshop's staff list. Active or not.
     *
     * Deliberately not narrowed to active staff: an invoice from three months ago
     * was done by whoever did it, and somebody who has left since does not stop
     * having done the work. Refusing that would leave the record permanently
     * wrong in order to enforce a rule about who can be *given* work today —
     * which is the roster's job, and {@see slots()} does it there.
     *
     * The global tenant scope is what makes this a real check: another workshop's
     * employee is not found at all.
     */
    private function assertEmployeeExists(int $employeeId): void
    {
        if ($this->employees->findById($employeeId) === null) {
            throw InvalidAttributionException::unknownEmployee($employeeId);
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        return (int) $value;
    }
}
