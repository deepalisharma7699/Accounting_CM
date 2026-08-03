# Accounting Module

The functional accounting core. Built in slices; this document grows with them.

| Step | Delivers | Status |
| --- | --- | --- |
| 2 | Chart of accounts | **Done** |
| 3 | Journal entries + the posting engine — [ledger-module.md](ledger-module.md) | **Done** |
| 4 | Parties (customers / vendors) | Next |
| 5 | Payments & receipts | |
| 6–8 | Items, inventory with WAC, the bill engine | |

## Chart of Accounts

> *A ledger is just a filtered view of journal entries by account. You never
> write to a ledger directly — you write balanced journal entries, and every
> ledger (Cash, Vendor X, Sales) is a query. Get the journals right and every
> ledger is automatically correct.* — the PRD

Which is why `chart_of_accounts` has **no balance column**. A stored running
balance drifts out of step with its entries; a derived one cannot.

### The design decision that matters

The posting engine must be able to say "debit COGS". How it names that account
determines whether the books survive a workshop editing their own chart.

| Approach | Breaks when |
| --- | --- |
| By name — `where('name', 'COGS')` | Anyone renames the account |
| By id — `find(12)` | Ids differ per tenant; unusable in shared code |
| By code — `where('code', '5000')` | The chart is renumbered, or Tally needs different codes |
| **By `system_key`** | Never — it is not user-editable |

So [`SystemAccount`](../app/Enums/SystemAccount.php) is an enum of fifteen
cases, its value stored in `chart_of_accounts.system_key`, and
`ChartOfAccountService::system(SystemAccount::Cogs)` is the **only** approved
way for business logic to reach a named account.

The visible `code` is display and reporting only. A workshop's accountant could
renumber the entire chart tomorrow and not one posting template would change.

### Schema

```
chart_of_accounts
  id, tenant_id, code, name, description,
  type, system_key (nullable), is_active, timestamps

  unique (tenant_id, code)
  unique (tenant_id, name)
  unique (tenant_id, system_key)
```

Tenant-owned, so it uses `BelongsToTenant` and `tenant_id` is **NOT NULL** —
both enforced by `TenantIsolationInvariantTest`. Every workshop holds its own
copy of all fifteen accounts; codes are unique per tenant, not globally.

`is_system` is **derived** from `system_key !== null` rather than stored, so the
two can never disagree.

### The five types

[`AccountType`](../app/Enums/AccountType.php) encodes two rules the whole
ledger depends on, so neither is re-implemented in reporting code:

| Type | Normal balance | Statement | Code band |
| --- | --- | --- | --- |
| Asset | Debit | Balance sheet | 1000–1999 |
| Liability | Credit | Balance sheet | 2000–2999 |
| Equity | Credit | Balance sheet | 3000–3999 |
| Income | Credit | Profit & loss | 4000–4999 |
| Expense | Debit | Profit & loss | 5000–5999 |

**Normal balance** is which side *increases* the account. Debit and credit are
not "in" and "out" — they are the left and right columns, and their meaning
depends on the account. A debit to Cash increases it; a debit to Sales
decreases it. Assets and expenses are debit-normal, everything else
credit-normal, which is the accounting identity
(Assets + Expenses = Liabilities + Equity + Income) expressed as code.

**Code bands are enforced, not suggested.** An expense numbered 1500 would sort
into the assets in every report that groups by code, and the mistake would not
surface until the balance sheet looked wrong. `POST /accounts` returns 409
`ACCOUNT_CODE_OUT_OF_BAND` instead.

### The seeded fifteen

| Code | Account | Type |
| --- | --- | --- |
| 1010 | Cash in Hand | Asset |
| 1020 | Bank Account | Asset |
| 1030 | UPI / Wallet | Asset |
| 1200 | Inventory | Asset |
| 1300 | GST Input | Asset |
| 1400 | Sundry Debtors (Receivables) | Asset |
| 1500 | Staff Advance | Asset *(Phase 2)* |
| 2100 | Sundry Creditors (Payables) | Liability |
| 2200 | GST Output | Liability |
| 3000 | Opening Balance Equity | Equity |
| 4000 | Sales | Income |
| 4100 | Service Income | Income |
| 5000 | COGS | Expense |
| 5100 | Misc Expense | Expense |
| 5200 | Salary Expense | Expense *(Phase 2)* |

Cash, Bank and UPI are separate accounts because the PRD models payment mode as
a *choice of asset account* — that is what gives split payments separate
cash/bank/UPI ledgers for free once `transaction_payments` lands.

The two Phase 2 accounts are seeded now so payroll needs no migration later.
Nothing in Phase 1 posts to them.

### Provisioning

`TenantService::createTenant()` calls `ChartOfAccountProvisioner::seedFor()`
**inside the same transaction that creates the workshop**. A tenant can
therefore never exist without a chart of accounts — the posting engine has no
fallback for a missing one. `TenantFactory` seeds too, so a test can never
construct a workshop production could not.

Backfill, for when a new `SystemAccount` case is added after tenants exist:

```bash
php artisan accounts:seed --dry-run       # report what is missing
php artisan accounts:seed                 # every workshop
php artisan accounts:seed --tenant=3      # one workshop
```

Idempotent and **create-only**. An account that already exists is left
completely alone — a workshop that renamed "UPI / Wallet" to "PhonePe" must not
have that undone, and the engine does not care, because it resolves on
`system_key`.

### What can be edited

| | System account | Workshop's own |
| --- | --- | --- |
| Name, description | ✅ | ✅ |
| Code | ❌ `ACCOUNT_SYSTEM_IMMUTABLE` | ✅ (band checked) |
| Archive (`is_active`) | ❌ the engine needs it | ✅ |
| Type | ❌ | ❌ |
| Delete | ❌ | ❌ |

**Type is immutable for every account, always.** Reclassifying would silently
move every journal entry ever posted against it onto a different financial
statement. An account of the wrong type is archived and replaced. `type` is
absent from `UpdateAccountRequest` entirely.

**Nothing is ever deleted.** There is no `DELETE` route and no `DELETE:ACCOUNTS`
permission. An account that has been posted to must survive or its journal
entries lose their name and every historical report changes retrospectively —
so accounts are archived with `PATCH {"is_active": false}` and simply stop
appearing in pickers.

### Endpoints

`/api/v1`, guarded by `auth.jwt` and tenant-scoped by the global scope.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/accounts` | `READ:ACCOUNTS` |
| GET | `/accounts/types` | `READ:ACCOUNTS` |
| GET | `/accounts/{id}` | `READ:ACCOUNTS` |
| POST | `/accounts` | `WRITE:ACCOUNTS` |
| PATCH | `/accounts/{id}` | `UPDATE:ACCOUNTS` |

`GET /accounts/types` publishes the five types with their code bands and normal
balances, so a client can render the "new account" form without hard-coding
accounting rules of its own.

Roles: `OWNER` holds read/write/update; `DATA_ENTRY` holds read, because
entering a transaction means choosing accounts from the chart.

#### Error codes

| Status | Code | Cause |
| --- | --- | --- |
| 403 | `ACCOUNT_SYSTEM_IMMUTABLE` | Renumbering or archiving a system account |
| 409 | `ACCOUNT_CODE_OUT_OF_BAND` | Code outside the type's band |
| 409 | `ACCOUNT_CODE_TAKEN` | Duplicate code in this workshop |
| 409 | `ACCOUNT_NAME_TAKEN` | Duplicate name in this workshop |

### The screen

`/accounts` — Blade shell + a lazily loaded ES module, following the same
pattern as the users and roles pages.

Rendered as **five typed blocks, not a paginated list**. A chart of accounts is
small by nature and an accountant reads it as five groups, so the page fetches
the whole thing (`per_page=200`) and groups it in statement order — asset,
liability, equity, income, expense. Each group header carries its code band and
which side increases it, which makes the numbering scheme self-explanatory
instead of something to look up.

| Behaviour | Detail |
| --- | --- |
| Code suggestion | Choosing a type fills the next free code in its band, stepping by 10 so related accounts can sit together |
| Band feedback | The band is shown as a hint and checked client-side, so the rule is explained before a round trip rather than after a 409 |
| System accounts | Locked controls stay **visible but disabled**, with the reason in the tooltip — the option does not silently vanish |
| Type | Disabled when editing anything, system or not |
| Archive | Confirmation explains that history is kept and the account can be restored |
| Reveal | The sidebar's Accounting entry needs **both** `READ:ACCOUNTS` and workshop membership — a platform admin holds every grant but has no books |
| No workshop | A platform admin who types the URL gets an explanatory empty state, not a red error |

Type metadata comes from `GET /accounts/types`, so the bands and normal
balances are never duplicated in JavaScript.

### Performance note

`EloquentChartOfAccountRepository` is bound as a **singleton** and memoises
resolved system accounts per tenant for the life of the request. Once posting
exists a single sale resolves five or six of them, and they cannot change
mid-request. The memo is dropped wholesale on any write.

### Tests

| File | Proves |
| --- | --- |
| `tests/Unit/AccountTypeTest.php` | The accounting rules themselves — normal balances, band disjointness, the catalogue's internal consistency. No database. |
| `ChartOfAccountProvisioningTest` | Every workshop is born with all fifteen accounts, correctly typed and banded; backfill is idempotent and never clobbers a rename. |
| `ChartOfAccountApiTest` | The HTTP surface, tenant scoping, and every immutability rule above. |
| `PagesRenderTest` | The `/accounts` shell compiles, is permission-gated, and leaks no ledger data to anonymous visitors. |

```bash
php artisan test --filter='Accounting|AccountType'
```

### Where Step 3 hooks in

`ChartOfAccountService::system(SystemAccount)` is the posting engine's entry
point. It throws rather than returning null: a transaction that cannot find its
Sales account must not post half of itself, and a missing system account is an
install fault, not a user error.

`AccountType::normalBalance()` is what turns a posting template into debits and
credits, and what decides which side of a ledger an account's balance sits on.

Both are now in use — see [ledger-module.md](ledger-module.md). Two consequences
for this module worth knowing:

* **An archived account cannot be posted to** (`JOURNAL_ACCOUNT_ARCHIVED`). Its
  history stays readable; new entries do not go there.
* **An account is still never deleted**, and now it demonstrably cannot be:
  `journal_entries.account_id` is a `restrictOnDelete` foreign key, so the
  database refuses to remove an account that has ever been posted to.
