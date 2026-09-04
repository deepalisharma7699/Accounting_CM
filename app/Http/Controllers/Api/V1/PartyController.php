<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PartyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ledger\LedgerRequest;
use App\Http\Requests\Party\IndexPartyRequest;
use App\Http\Requests\Party\StorePartyRequest;
use App\Http\Requests\Party\UpdatePartyRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\PartyResource;
use App\Models\Party;
use App\Services\Accounting\PartyLedgerService;
use App\Services\Accounting\PartyService;
use App\Services\Accounting\PartyStatementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Customers and suppliers, and the statements derived from their transactions.
 *
 * Two permissions meet in this controller, and the split is deliberate. PARTIES
 * is authority over the *record* — who exists, what they are called, how they
 * are reached, and **where they stand**. LEDGER is authority over the *books*,
 * and `ledger()` and `statement()` are guarded by it rather than by PARTIES.
 *
 * The line falls between one number and the entries behind it, not between the
 * name and the money — M21. A party's `outstanding` is two figures, one per
 * side, and it travels with the record under READ:PARTIES because deciding
 * whether to sell on credit is part of writing the invoice: the counter clerk
 * who may raise one is exactly the person who must be able to see the credit
 * already extended, and they hold PARTIES and TRANSACTIONS and no LEDGER. Every
 * entry that moved the position, in date order, with a running balance that
 * reconciles to the control account, is a different question asked by a
 * different person, and it stays behind LEDGER.
 *
 * `lifetime` rides along with `outstanding` for the same reason and out of the
 * same query — it is the gross sides of the totals that were summed anyway, and
 * "billed ₹4,00,000, received ₹3,58,000" says nothing the outstanding figure
 * does not already imply.
 *
 * There is no `destroy` for a party who has been traded with. Their entries
 * would lose the name that explains them, so they are archived
 * (`PATCH {"is_active": false}`) exactly as an account is.
 */
class PartyController extends Controller
{
    public function __construct(
        private readonly PartyService $parties,
        private readonly PartyLedgerService $ledger,
        private readonly PartyStatementService $statements,
    ) {}

    /**
     * GET /api/v1/parties/{party}/statement
     *
     * The customer and vendor statements of the brief's §14 and §15 — M16.
     * Headline totals, then one row per invoice saying what it was worth and
     * what is left on it, then the receipts that settled them.
     *
     * Distinct from `{party}/ledger`, and both are wanted. The ledger is the
     * accountant's view — every entry that moved the party's position, with a
     * running balance that reconciles to the control account. This is the
     * counter view: which bills are outstanding, and by how much. Neither can
     * answer the other's question, which is why there are two routes rather than
     * one with a mode flag.
     *
     * `role` decides which half of the relationship is being asked about,
     * because a great many workshop counterparties are both — the dealer who
     * buys motors and sells bearings — and the two are settled separately.
     * Defaults to whichever single role the party holds, and to customer for
     * somebody who holds both, since that is the statement a workshop reads far
     * more often.
     */
    public function statement(Request $request, int $party): JsonResponse
    {
        $record = $this->parties->find($party);

        $role = PartyRole::tryFrom((string) $request->query('role', ''))
            ?? ($record->hasRole(PartyRole::Customer) ? PartyRole::Customer : PartyRole::Vendor);

        $statement = $this->statements->forParty($record, $role);

        return ApiResponse::success(
            [
                'bills' => $statement['bills'],
                'settlements' => $statement['settlements'],
            ],
            null,
            200,
            [
                'party' => [
                    'id' => $record->id,
                    'name' => $record->name,
                    'roles' => $record->roles ?? [],
                    'gstin' => $record->gstin,
                    'is_active' => $record->is_active,
                ],
                'role' => $role->value,
                'totals' => $statement['totals'],
            ],
        );
    }

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * GET /api/v1/parties
     *
     * Outstanding figures are opt-in via `with_position=1`, and cost one extra
     * query for the whole page rather than one per row.
     */
    public function index(IndexPartyRequest $request): JsonResponse
    {
        $page = $this->parties->paginate($request->filters(), $request->perPage());

        if ($request->wantsPosition()) {
            $this->attachPositions(collect($page->items()));
        }

        if ($request->wantsActivity()) {
            $this->attachActivity(collect($page->items()));
        }

        return ApiResponse::paginated($page, PartyResource::class);
    }

    /**
     * GET /api/v1/parties/meta
     *
     * The roles a party can hold, with the position each implies — so a client
     * builds its filters and its forms from the server's answer rather than
     * from a hard-coded copy that drifts when M16 adds staff.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'roles' => array_map(fn (PartyRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'position_label' => $role->positionLabel(),
                'normal_balance' => $role->normalBalance()->value,
            ], PartyRole::cases()),
        ]);
    }

    /**
     * GET /api/v1/parties/{party}
     */
    public function show(int $party): JsonResponse
    {
        $record = $this->parties->find($party);

        // Both, unconditionally: one party is one row, so there is no page to
        // pay for it over, and the drawer that reads this wants the whole
        // picture in one round trip.
        $this->attachPositions(collect([$record]));
        $this->attachActivity(collect([$record]));

        return ApiResponse::success(new PartyResource($record));
    }

    /**
     * GET /api/v1/parties/{party}/ledger
     *
     * A party statement: every entry that moved their position, in date order,
     * each with the running balance after it. Guarded by READ:LEDGER, not
     * READ:PARTIES.
     */
    public function ledger(LedgerRequest $request, int $party): JsonResponse
    {
        $result = $this->ledger->forParty(
            $this->parties->find($party),
            $request->period(),
            $request->perPage(),
        );

        $page = $result['entries'];
        $record = $result['party'];

        return ApiResponse::success(
            JournalEntryResource::collection(collect($page->items()))->resolve(request()),
            null,
            200,
            [
                'party' => [
                    'id' => $record->id,
                    'name' => $record->name,
                    'roles' => $record->roles ?? [],
                    'gstin' => $record->gstin,
                    'is_active' => $record->is_active,
                ],
                // Signed towards the workshop: a positive net means the party
                // owes money, a negative one means the workshop does.
                'opening_balance' => $result['opening']->amount(),
                'closing_balance' => $result['closing']->amount(),
                'outstanding' => [
                    'receivable' => $result['position']['receivable']->amount(),
                    'payable' => $result['position']['payable']->amount(),
                    'net' => $result['position']['net']->amount(),
                ],
                'period' => [
                    'from' => $request->input('from'),
                    'to' => $request->input('to'),
                    'debit' => $result['period']['debit']->amount(),
                    'credit' => $result['period']['credit']->amount(),
                ],
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                    'has_more' => $page->hasMorePages(),
                ],
            ],
        );
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * POST /api/v1/parties
     */
    public function store(StorePartyRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $party = $this->parties->create($payload);

        return ApiResponse::created(
            new PartyResource($party),
            'Party created.',
            $this->gstinWarnings($payload['gstin'] ?? null, $party->id),
        );
    }

    /**
     * PATCH /api/v1/parties/{party}
     *
     * Also the archive control: `{"is_active": false}`.
     */
    public function update(UpdatePartyRequest $request, int $party): JsonResponse
    {
        $payload = $request->payload();
        $record = $this->parties->update($party, $payload);

        return ApiResponse::success(
            new PartyResource($record),
            'Party updated.',
            200,
            array_key_exists('gstin', $payload)
                ? $this->gstinWarnings($record->gstin, $record->id)
                : [],
        );
    }

    /**
     * DELETE /api/v1/parties/{party}
     *
     * Only ever reaches a party nothing points at. Anything with transactions
     * behind it is refused with `PARTY_IN_USE` and an explanation.
     */
    public function destroy(int $party): JsonResponse
    {
        $this->parties->delete($party);

        return ApiResponse::message('Party deleted.');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     |-------------------------------------------------------------------- */

    /**
     * Hang each party's outstanding position — and what they have traded to
     * date — on the model for the resource to serialise. One query for the
     * whole collection, and the lifetime figures are the gross sides of the
     * totals that same query already summed, so they are free.
     *
     * @param  Collection<int, Party>  $parties
     */
    private function attachPositions(Collection $parties): void
    {
        $summaries = $this->ledger->summariesFor($parties);

        foreach ($parties as $party) {
            $summary = $summaries[$party->id] ?? null;

            $party->setAttribute('outstanding', $summary['outstanding'] ?? null);
            $party->setAttribute('lifetime', $summary['lifetime'] ?? null);
        }
    }

    /**
     * Hang the dates the workshop last dealt with each party. One query for the
     * whole collection, and separate from the position because it reads the
     * transactions rather than the ledger.
     *
     * @param  Collection<int, Party>  $parties
     */
    private function attachActivity(Collection $parties): void
    {
        $activity = $this->parties->activityFor($parties);

        foreach ($parties as $party) {
            $party->setAttribute('activity', $activity[$party->id] ?? null);
        }
    }

    /**
     * A shared GSTIN is reported, never refused — branches of one business file
     * under one, so the second branch is legitimate. But the commoner cause is
     * the same party entered twice, which splits one balance in half, so it is
     * put in front of the user while they can still merge the two.
     *
     * @return array<string, mixed>
     */
    private function gstinWarnings(?string $gstin, int $exceptId): array
    {
        $others = $this->parties->othersSharingGstin($gstin, $exceptId);

        if ($others->isEmpty()) {
            return [];
        }

        return [
            'warnings' => [[
                'code' => 'PARTY_GSTIN_DUPLICATE',
                'message' => sprintf(
                    'This GSTIN is already on %s. That is correct for a second branch of the same business — '.
                    'if it is the same party twice, use the existing record instead so their balance stays in '.
                    'one place.',
                    $others->pluck('name')->join(', ', ' and '),
                ),
                'party_ids' => $others->pluck('id')->all(),
            ]],
        ];
    }
}
