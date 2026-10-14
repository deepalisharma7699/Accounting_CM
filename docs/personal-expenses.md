# Personal expenses — the owner's own diary

The **Personal** tab of **Money in & out** (`#journal?tab=personal`). An owner
records what they spent out of their own pocket — groceries, school fees, fuel —
so they have a running record of it in the product they already open every day.

## It is not in the books

The money never passed through the workshop, so nothing here is a business
transaction:

- no voucher, no ledger line, no posting-engine call;
- not in the P&L, the trial balance, the Day Book, Insights or any report;
- an entry is **edited and deleted** like a diary line, not reversed.

Putting it in the books would either put a family's spending in the shop's P&L,
understating the profit that tax is paid on, or take cash out of a till it never
left. Do not add it to a report, and do not route it through `/transactions`.

A shop cost the owner happened to pay personally (shop rent, a part) is **not** a
personal expense, and the form says so. Recording that properly as the owner's
contribution to the business is a separate, not-yet-built option on the Expense
form.

## Who sees it

`PERSONAL_EXPENSES` (READ, WRITE, UPDATE, DELETE), granted to **OWNER only** —
including the old shared OWNER row, for owners not yet moved onto their workshop's
own roles. An owner may tick it onto a role of their own making. There is **one
diary per workshop**, shared by everyone holding the grant, and each entry records
who wrote it (`created_by`).

Kept private in three more ways: neither model is `Auditable`, so the History card
never shows it; the topbar search does not reach it; and the data bus announces
nothing on a write, because no other screen holds a copy of it.

## Shape

| Table | What |
|---|---|
| `personal_expense_categories` | The owner's own list, seeded with twelve (`PersonalCategoryDefaults`). Renamed, archived, restored; deleted only while nothing is filed under it. |
| `personal_expenses` | `date`, `category_id`, `amount`, `paid_by` (`cash`, `upi`, `card`, `bank` — `PersonalPaymentMethod`), `notes`, `created_by`. |

`paid_by` is its own enum, not `PaymentMode`, because `PaymentMode` decides which
ledger account money leaves and this money leaves none.

## API — `/api/v1/personal-expenses`

| Verb | Path | Grant |
|---|---|---|
| GET | `meta` — categories with counts, payment methods | READ |
| GET | `/` — `search`, `category_id`, `paid_by`, `from`, `to`; `meta.summary` totals the whole filter, split by category | READ |
| GET | `{id}` | READ |
| POST | `/` | WRITE |
| PATCH | `{id}` | UPDATE |
| DELETE | `{id}` | DELETE |
| GET / POST | `categories` | READ / WRITE |
| PATCH / DELETE | `categories/{id}` — `is_active` archives | UPDATE / DELETE |

Refusals: `PERSONAL_CATEGORY_NAME_TAKEN`, `PERSONAL_CATEGORY_IN_USE`,
`PERSONAL_CATEGORY_ARCHIVED`, `PERSONAL_CATEGORY_UNKNOWN`, and
`PERSONAL_EXPENSES_NOT_INSTALLED` (503) while the SQL step has not been run.

## Deploying

The schema is `database/manual/sql/2026_10_14_001_personal_expenses.sql` (§4.6).
The code works before it runs: nobody holds the grant yet, so the tab is not drawn,
and the API refuses cleanly. After running it, clear the cache
(`php artisan cache:clear`) or role grants take up to an hour to show.

Tests: `tests/Feature/Personal/PersonalExpenseTest.php`.
