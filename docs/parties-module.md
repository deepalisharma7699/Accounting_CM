# Parties

Who the workshop trades with, and what each of them owes.

A party is one record per counterparty — not one per relationship. The rewinding
trade is full of businesses on both sides of the counter: the shop that buys a
rewound motor this week sells you scrap copper the next. Modelling that as two
records is the classic mistake, because it splits one relationship into two
balances that are never netted or even looked at together.

> *Outstanding is computed, never stored.* — the PRD

## The two rules

Everything below is a consequence of these.

1. **No stored balance.** What a party owes is a sum over `journal_entries`,
   worked out on every read. There is no `outstanding` column, no nightly
   recalculation and no increment-on-post.
2. **One party, one ledger.** Roles are a set on one record, and the statement
   spans both control accounts regardless of which roles are ticked.

## Why the outstanding cannot drift

A party ledger and the control account it rolls up into are **the same rows,
summed two ways**.

```
Sundry Debtors  (1400)   = every debit and credit on that account
Alpha Motors' receivable = the subset of those whose transaction names Alpha
```

So the sum of every customer's receivable equals the Receivables balance by
construction — nothing reconciles them, and nothing can make them disagree.
`PartyLedgerTest::every_party_position_sums_to_its_control_account` asserts
exactly that after a mixed run of invoices, receipts and bills, plus a cash sale
with no counterparty at all.

A stored `outstanding` column would need updating on every post, every reversal
and every back-dated correction. The first path that forgot one would be wrong
in a way nobody notices until a customer disputes a statement months later.

## Schema

### `parties`

```
id, tenant_id, name, roles (json), gstin, state_code,
phone, email, address, notes, is_active, timestamps

  unique (tenant_id, name)
  index  (tenant_id, is_active, name)
  index  (tenant_id, gstin)
```

**`name` is unique per workshop.** Not tidiness: two rows called "Sharma
Traders" split one outstanding balance in half, and both halves look plausible.
Forcing the second to be named distinctly — "Sharma Traders (Pune)" — is the
whole protection.

**`gstin` is deliberately not unique.** A business with branches files one GSTIN
across all of them, so the second branch is legitimate. But the commoner cause
is the same party entered twice, so a duplicate is *reported* rather than
refused or ignored — see [Duplicate GSTINs](#duplicate-gstins).

**`state_code` is derived** from the first two characters of the GSTIN and never
accepted from the client. M9 decides CGST+SGST versus IGST by comparing it with
the workshop's own, and a hand-supplied one that disagreed with the GSTIN would
compute the wrong tax on every bill without ever looking wrong.

There is no balance column, and there never will be.

### `transactions.party_id`

Deferred from M4 rather than left sitting nullable and unconstrained until the
table it points at existed. It arrives with its foreign key, `restrictOnDelete`.

Nullable, and legitimately so: a depreciation journal, a correcting entry and a
cash expense have no counterparty. What makes a party ledger correct is not that
every transaction has one, but that every transaction *touching a control
account* does — which each posting template from M6 onwards guarantees by
construction.

## Roles

```
customer  →  Sundry Debtors   (1400)  debit-normal   "Receivable"
vendor    →  Sundry Creditors (2100)  credit-normal  "Payable"
```

Stored as a JSON array rather than a pair of booleans, because the set grows —
M16 adds staff — and a boolean per role means a migration each time. Filtering
uses `whereJsonContains`, always alongside the tenant index; a workshop deals
with hundreds of parties, not millions.

Role membership, **not** equality: filtering for customers still returns the
counterparty who is also a vendor. Otherwise the one record that models both
sides of a relationship disappears from both lists.

Roles are stored in enum order, so `["vendor","customer"]` and
`["customer","vendor"]` are the same stored value and two equivalent parties
compare equal.

### Roles do not decide which accounts are read

`PartyLedgerService` reads **both** control accounts for every party, whatever
roles the record holds.

Scoping the read to the roles would mean that dropping the "vendor" tag from
someone with an unpaid bill silently emptied that half of their ledger, while
the money stayed in the control account — a party balance and a trial balance
disagreeing, caused by an edit to a label. Roles classify a party for the people
using the system; they are not a filter on the books.

That is why removing a role needs no guard, and why there isn't one.

## The position

| Field | Meaning | Sign |
| --- | --- | --- |
| `receivable` | What they owe the workshop | On its own normal side |
| `payable` | What the workshop owes them | On its own normal side |
| `net` | `receivable − payable` | Positive: they owe you |

All three are reported, always. Netting a ₹40,000 receivable against a ₹38,000
payable into "₹2,000" is true and useless — the two are settled separately, on
different terms, and the screen says so in words rather than leaving a minus
sign to be read the wrong way round.

**Overpayment leaves a credit balance** rather than being refused: a customer who
pays ₹6,000 against a ₹5,000 invoice shows a receivable of −₹1,000. The money is
in the bank and it is theirs, so the books must say so. Forcing it onto the
payable side would claim a supplier relationship that does not exist.

The statement's running balance is signed the same way as `net` — a debit
against a party is money owed to the workshop, whichever control account it
landed in — and is cumulative from their first entry ever, not from the start of
the filtered period. A June statement therefore opens at the amount brought
forward, and page 2 continues from page 1 instead of restarting at zero.

### Cost

Listing outstanding figures is opt-in, via `with_position=1`, and costs **one**
query for the whole page rather than one per row. A picker asking for names has
no use for it and does not pay for it.

## Duplicate GSTINs

Reported, never refused, and never silently accepted:

```json
"meta": { "warnings": [{
  "code": "PARTY_GSTIN_DUPLICATE",
  "message": "This GSTIN is already on Verma Motors (Pune). …",
  "party_ids": [7]
}]}
```

The save succeeds — a second branch is a real arrangement — and the duplicate is
put in front of the user while they can still merge the two.

## Archiving and deletion

Same rule as an account, for the same reason.

| | When | Effect |
| --- | --- | --- |
| **Delete** | Only while *no* transaction names them | Row removed |
| **Archive** | Always | Hidden from pickers; ledger intact |

A party who has been traded with cannot be deleted: their entries would lose the
name that explains them. `Cr Sundry Creditors 12,000` is a number; `Cr Sundry
Creditors 12,000 — Bharat Winding Works` is a record. The refusal
(`PARTY_IN_USE`, 409) names the alternative rather than being a dead end, and
`restrictOnDelete` on `transactions.party_id` backs it up for anything that does
not go through the service.

**A draft counts.** Nothing reached the ledger, but deleting the party would
leave a voucher pointing at nothing that could never be posted.

An archived party **can still have a mistake reversed**. Archiving means "no new
business with them"; it cannot mean "the last entry we made against them is
permanently wrong". This is the one place `PostingEngine` accepts an archived
party, and it is why `assertPostable()` takes `allowArchivedParty`.

## Posting

`PostingBatch` carries an optional `partyId`. It sits on the batch rather than on
a line because a party belongs to the *event* — one bill is with one customer,
however many accounts it moves.

Validation lives in `PostingEngine::assertPartyUsable()`, not in a form request,
because every future entry point passes through the engine: M6's payments, M9's
bills, M11's importer, M15's capture agent. A party id validated in one
controller says nothing about the other five. The same reasoning puts the account
and balance checks there.

| Refusal | Error code | Status |
| --- | --- | --- |
| Party not in this workshop | `PARTY_UNKNOWN` | 422 |
| Party archived | `PARTY_ARCHIVED` | 422 |
| Duplicate name | `PARTY_NAME_TAKEN` | 409 |
| No role at all | `PARTY_ROLE_REQUIRED` | 409 |
| Deleting a party with transactions | `PARTY_IN_USE` | 409 |

Tenant isolation needs no code of its own: another workshop's party id simply
does not resolve under the global scope, so a transaction cannot be attributed
across a boundary even if an id is guessed.

**A reversal carries the original's party.** If it did not, the control account
would net to zero while the party stayed permanently in debt — the two
disagreeing about the same money, which is the failure a derived balance exists
to make impossible.

## Endpoints

`/api/v1`, behind `auth.jwt` and tenant-scoped by the global scope.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/parties` | `READ:PARTIES` |
| GET | `/parties/meta` | `READ:PARTIES` |
| GET | `/parties/{id}` | `READ:PARTIES` |
| GET | `/parties/{id}/ledger` | `READ:PARTIES` **+** `READ:LEDGER` |
| POST | `/parties` | `WRITE:PARTIES` |
| PATCH | `/parties/{id}` | `UPDATE:PARTIES` — also archive/restore |
| DELETE | `/parties/{id}` | `DELETE:PARTIES` — untraded parties only |

`GET /transactions?party_id=` drills from a statement back to the events behind
it, and `POST /transactions/journal` accepts an optional `party_id`.

### Two permissions, deliberately

| | `PARTIES` | `LEDGER` |
| --- | --- | --- |
| Authority to | Know who exists | Read the money |
| `OWNER` | Read, write, update, delete | ✅ |
| `DATA_ENTRY` | Read, **write** | ❌ |

`DATA_ENTRY` holds `WRITE:PARTIES` where it holds only `READ:ACCOUNTS`, because
the customer standing at the counter is new far more often than the chart needs
a new account. A clerk who had to fetch the owner to record a walk-in would end
up recording the sale against the wrong party, or not at all. Editing and
deleting an existing party stays with the owner — and reading what anyone owes
needs `LEDGER`, which `DATA_ENTRY` does not have.

## Screens

`/customers` and `/vendors`, both gated on `READ:PARTIES` plus workshop
membership. `/parties` — the single screen these replaced — redirects to
`/customers`.

**Two screens over one table.** Each filters on role *membership*, not equality,
which is the whole reason the split is safe: a counterparty holding both roles
appears on **both** lists, labelled "Also a vendor" / "Also a customer", as one
record with one combined ledger. The failure mode the split must not
reintroduce is two *records* for one counterparty, which splits a single balance
into halves that are never netted or even looked at together. Three things guard
against it:

- The create form offers **both** role checkboxes, with the current screen's
  ticked. Somebody adding a supplier they also sell to ticks the second box
  rather than creating a second record.
- Dropping this screen's role saves fine and reports where the record went,
  rather than letting it vanish and read as a failed save.
- **The statement is always the combined ledger**, both control accounts,
  whichever list opened it. Scoping it to the role of the screen would hide half
  of what a dual-role counterparty owes — and the hidden half is the one nobody
  would then chase.

Each list leads with its own side of the position and states the other side on
the row where there is one, never netted: "they owe you ₹40,000 and you owe them
₹38,000" is two facts settled separately, and "₹2,000" is true and useless.

The two screens share `resources/views/partials/counterparty-page.blade.php` and
`resources/js/pages/counterparty.js`; `customers.js` and `vendors.js` are
one-line wrappers supplying the wording and which side to lead with. Two
near-identical copies is how a fix lands on one screen and not the other.

One counterparty opens in a drawer over the list — Overview, their bills, their
payments, and who changed the record — and the statement as a modal. Both are
read while deciding something about the party, and losing the list to see them
is what makes people stop checking.

### Two extra reads, both opt-in

| Flag | Adds | Cost |
| --- | --- | --- |
| `with_position=1` | `outstanding` **and** `lifetime` | One query per page |
| `with_activity=1` | `activity` — when they were last dealt with | One query per page |

`lifetime` rides along free with `with_position`: `billed` and `received` are the
gross debits and credits on Receivables, `purchased` and `paid` those on
Payables, summed from the same rows the position is netted from. So
`billed − received` *is* the receivable, by construction — the two halves of the
drawer cannot tell different stories.

`activity` reads the transactions rather than the ledger, and reports dates only:
`last_transaction_at`, `last_sale_at`, `last_purchase_at`, `last_payment_at` and
`transaction_count`, over **posted** transactions. Amounts stay with the ledger,
because `transactions.total` is a listing convenience and summing it would
quietly disagree with the control accounts the moment a bill was edited.

Both are null when not asked for, and zero-filled or null-filled when the answer
is genuinely "nothing" — an em dash distinguishes "nobody asked" from "nothing
owed", and showing the first as the second would tell a reader an account is
settled when nobody looked.

## Tests

```bash
php artisan test --filter='Party|PagesRender'
```

| File | Proves |
| --- | --- |
| `PartyTest` | The record: roles, canonical ordering, naming, GSTIN, archiving, deletion, tenancy |
| `PartyLedgerTest` | The derivation: positions, combined ledgers, running balances, reversal, drafts, and the control-account reconciliation |
| `PartyApiTest` | The HTTP surface, permissions, tenant isolation, attribution through the journal endpoint |
| `PagesRenderTest` | The shell compiles, is gated, and leaks nothing to anonymous visitors |

`tests/Concerns/InteractsWithLedger.php` gained a `party:` argument on
`postSimpleJournal()` and `batchFor()`, and **`positionOf()`** — a party's three
figures as decimal strings, so an assertion reads `'5000.00'` rather than
comparing objects. Use it rather than re-deriving.

There is deliberately no way to give a party a balance except by posting through
the engine, for the same reason there is no `JournalEntryFactory`.

## Notes for the next module

* **M6** added `payment` and `receipt` with templates D and E, and added **no
  reporting code at all** — a payment reduces `payable` and a receipt reduces
  `receivable` because both are already sums over the same rows. See
  [payments-module.md](payments-module.md).
* It also made `party_id` **required** for those two types, and required the party
  to hold the matching role: debiting Sundry Creditors *is* the claim "we owed
  this business money". That is the one place a role gates a write, and it does
  not weaken the rule above — roles still never filter a *read*. The two are
  different questions, and M6's doc spells out why.
* An overpayment leaving a credit balance, decided here, is what M6 implemented
  in both directions: a supplier paid too much shows a negative payable, which is
  an advance and a real thing.
* **M9** reads `parties.state_code` against `tenants.state_code` to decide
  CGST+SGST versus IGST. Both are derived from their GSTIN in exactly one place.
* **M11** imports opening receivables and payables as `Dr Customer / Cr OBE` and
  `Dr OBE / Cr Vendor`, each naming a party. Nothing about outstanding needs
  importing — post the entries and the position follows.
* Fuzzy party matching for M11 and M15 belongs in a resolver of its own, not
  here. `sharingGstin()` is the only lookup in this module that is not exact.
