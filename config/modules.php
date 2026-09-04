<?php

/*
| The modules, and everything the shell needs to know about them.
|
| This is the registry the dashboard's card grid is built from, the whitelist the
| fragment route checks against, and the source of truth for which grant each
| module needs. It replaces `$primaryNav` and `$adminNav`, which lived in
| partials/sidebar.blade.php until the sidebar was removed — the data is moved
| here rather than copied, so there is still exactly one place that says a module
| exists and who may see it.
|
| Per key:
|
|   label        what the card and the breadcrumb call it
|   description  one line on the card, saying what the module is for
|   icon         a name from resources/views/components/icon.blade.php
|   tone         the icon chip's colours, as a Tailwind class pair
|   permission   the grant needed to see the card, or null for none
|   workspace    true when the module belongs to a single workshop's books
|   enabled      false while a module is waiting to be converted — see below
|
| ## `enabled`
|
| Ten are converted to the §2A flow and on: Sales, Purchase, Items, Stock,
| Customers, Vendors, Insights, Staff, Users, Roles. Ten are off: Bills, Jobs,
| Transactions, Accounting, Ledger, Uploads, Workshops, Settings, Opening
| balances, History. They still open on a list with a modal create, which is the
| *only* reason each is off — turning one back on is `'enabled' => true` and
| nothing else.
|
| **Off is not unbuilt.** Every module below has a finished backend, a finished
| `pages/*.js`, a fragment view and tests; several are the only way to reach a
| capability the workshop needs (an expense, a receipt not tied to a bill, the
| audit trail, go-live balances). What each one still holds that no enabled card
| covers, and what has already moved to one that is on, is written up in
| docs/hidden-modules.md — read it before converting one, because part of some of
| these screens must *not* be rebuilt (§5.1).
|
| Off means off, not merely unlisted. A disabled module gets no card, and its
| fragment route answers 404 — a URL somebody kept must not be a way round the
| switch. The redirect from its old path stays registered either way, so nothing
| that links to it breaks; it lands on the dashboard instead. What is *not* shut
| is the API behind it: `/api/v1/attachments` answers whether or not the Uploads
| card exists. The switch governs the UI, and every endpoint keeps its own grant.
|
| `permission` and `workspace` are independent gates and both are applied — a
| platform super-admin holds every grant but owns no books, so a permission check
| alone would offer them a chart of accounts they cannot load. Authority is not
| membership. Both are also enforced server-side on every endpoint behind the
| module; the card is presentation only.
|
| There is no entry for a module that does not exist. The sidebar carried an "AI
| Center" pointing at `null`, and a card that opens nothing teaches somebody that
| this part of the product is broken.
*/

return [

    /*
    | The day's work.
    */
    'primary' => [

        /*
        | Sales — what the workshop sold, and what is still owed for it.
        |
        | First in the grid because it is the thing done most: a counter writes
        | several invoices between one delivery and the next.
        |
        | Its own card rather than a mode inside Bills, for the reason Purchase
        | has one. A module opens on its create form (§2A.1), so a combined
        | module would have to open by asking "sale or purchase?" — the
        | ledger-shaped screen the Bills note below objects to. One card per
        | document kind lands straight on the right form, with the right
        | counterparty and nothing to choose first.
        |
        | Gated on TRANSACTIONS, the same grant the counter at /bills/new already
        | needs — so adding this module re-seeds nothing. Credit notes are the
        | same authority: taking goods back from a customer is capturing a
        | business event, not a separate power.
        */
        'sales' => [
            'label' => 'Sales',
            'description' => 'Invoices, and what customers owe',
            'icon' => 'receipt',
            'tone' => 'bg-emerald-50 text-emerald-600',
            'permission' => 'READ:TRANSACTIONS',
            'workspace' => true,
            // Converted to the §2A flow: opens on the invoice form, with the
            // invoices and credit notes behind "Show list".
            'enabled' => true,
        ],

        /*
        | Bills — what the workshop sells, and what it costs to be open.
        |
        | **Purchases left this module** when Purchase was converted, and the
        | reasoning is worth keeping. The note here used to say that one Bills
        | module avoided making somebody "choose a transaction type before
        | offering an invoice form". Under §2A that argument inverts: a module
        | opens *on its create form*, so a combined module would have to open by
        | asking sale-or-purchase — which is the ledger-shaped screen the
        | objection was against. One card per document kind lands straight on the
        | right form with the right counterparty and nothing to choose first.
        |
        | What is left here is sales and expenses, and they still belong
        | together: an expense is not a purchase — it is what it costs to be open
        | rather than what was bought to sell — but both are written from the
        | same counter by the same person, and neither justifies a card alone.
        | This module is converted after Purchase, following the same shape.
        */
        'bills' => [
            'label' => 'Bills',
            'description' => 'Sales and expenses',
            'icon' => 'receipt',
            'tone' => 'bg-violet-50 text-violet-600',
            'permission' => 'READ:TRANSACTIONS',
            'workspace' => true,
            'enabled' => false,
        ],

        /*
        | The bench — M19. Gated on WORKSHOP_JOBS rather than on TRANSACTIONS,
        | because a job has nothing in the books until somebody bills it. The
        | split is what keeps "record the day's work" and "post to the ledger"
        | separate authorities.
        */
        'jobs' => [
            'label' => 'Jobs',
            'description' => 'Motors on the bench',
            'icon' => 'wrench',
            'tone' => 'bg-amber-50 text-amber-600',
            'permission' => 'READ:WORKSHOP_JOBS',
            'workspace' => true,
            'enabled' => false,
        ],

        'journal' => [
            'label' => 'Transactions',
            'description' => 'Receipts, payments and journal vouchers',
            'icon' => 'file-text',
            'tone' => 'bg-blue-50 text-blue-600',
            'permission' => 'READ:TRANSACTIONS',
            'workspace' => true,
            'enabled' => false,
        ],

        /*
        | The catalogue — what the workshop deals in — and, since M8, what is
        | actually on the shelf. Still two modules rather than one "Inventory",
        | because they answer different questions and are gated on different
        | grants: ITEMS is the record, STOCK is the position. Knowing the
        | workshop deals in 5 HP motors is not knowing four are in the corner.
        */
        'items' => [
            'label' => 'Items',
            'description' => 'Catalogue, stock levels and pricing',
            'icon' => 'package',
            'tone' => 'bg-blue-50 text-blue-600',
            'permission' => 'READ:ITEMS',
            'workspace' => true,
            // Converted to the §2A flow: opens on its create form, with the
            // catalogue behind "Show list".
            'enabled' => true,
        ],

        'stock' => [
            'label' => 'Stock',
            'description' => 'What is on the shelf, and what is running out',
            'icon' => 'layers',
            'tone' => 'bg-violet-50 text-violet-600',
            'permission' => 'READ:STOCK',
            'workspace' => true,
            // Converted to the §2A flow. Read-mostly under §2A.10, so it opens
            // on its list rather than on a create form — nothing is created
            // here, and "how many are left" is the only question it is opened
            // to answer.
            'enabled' => true,
        ],

        /*
        | Purchase — what the workshop buys in, and what is owed for it.
        |
        | Its own card rather than a mode inside Bills, and the reasoning is §2A
        | rather than the ledger's. A module opens on its create form; a combined
        | Bills module would have to open by asking "sale or purchase?", which is
        | the screen-organised-around-the-ledger the note above objects to. One
        | card per document kind lands straight on the right form.
        |
        | Gated on TRANSACTIONS, the same grant the counter needs — so nothing has
        | to be re-seeded for this module to work. Purchase returns (debit notes)
        | are the same authority: sending goods back to a supplier is capturing a
        | business event, not a separate power.
        */
        'purchase' => [
            'label' => 'Purchase',
            'description' => 'Bills from suppliers, and what is owed',
            'icon' => 'shopping-cart',
            'tone' => 'bg-blue-50 text-blue-600',
            'permission' => 'READ:TRANSACTIONS',
            'workspace' => true,
            // Converted to the §2A flow: opens on the purchase bill form, with
            // the bills and debit notes behind "Show list".
            'enabled' => true,
        ],

        /*
        | Two modules over one `parties` table, filtered on role *membership* —
        | so the shop that buys a rewound motor and sells you scrap copper is one
        | record appearing on both, marked as such, rather than two records whose
        | halves of a single balance never meet. Both on READ:PARTIES, because
        | they are the same records.
        */
        'customers' => [
            'label' => 'Customers',
            'description' => 'Who buys, and what they owe',
            'icon' => 'users',
            'tone' => 'bg-emerald-50 text-emerald-600',
            'permission' => 'READ:PARTIES',
            'workspace' => true,
            // Converted to the §2A flow: opens on the record form, with the
            // customers behind "Show list".
            'enabled' => true,
        ],

        'vendors' => [
            'label' => 'Vendors',
            'description' => 'Who supplies, and what is owed',
            'icon' => 'truck',
            'tone' => 'bg-amber-50 text-amber-600',
            'permission' => 'READ:PARTIES',
            'workspace' => true,
            // Converted to the §2A flow: opens on the record form, with the
            // suppliers behind "Show list".
            'enabled' => true,
        ],

        'accounts' => [
            'label' => 'Accounting',
            'description' => 'Ledger, journal and the chart of accounts',
            'icon' => 'book-open',
            'tone' => 'bg-emerald-50 text-emerald-600',
            'permission' => 'READ:ACCOUNTS',
            'workspace' => true,
            'enabled' => false,
        ],

        // Reading the whole financial position, which is a different authority
        // from capturing events — hence LEDGER rather than TRANSACTIONS.
        'ledger' => [
            'label' => 'Ledger',
            'description' => 'Trial balance, account by account',
            'icon' => 'bar-chart',
            'tone' => 'bg-blue-50 text-blue-600',
            'permission' => 'READ:LEDGER',
            'workspace' => true,
            'enabled' => false,
        ],

        /*
        | Insight — M23. What the numbers mean, as opposed to what they are.
        |
        | ## Why this replaced the `reports` card rather than joining it
        |
        | There was a card here called Reports, switched off, holding M12's four
        | statements: the day book, the profit & loss, the GST summary and the
        | parked-draft worklist. It is now the last four tabs of this module, and
        | the reason is §5.1 rather than tidiness.
        |
        | Two cards would both have answered "how is the business doing", and a
        | workshop owner looking for sales-by-month would have had to guess which
        | of them had it. They would also have needed two period pickers, two
        | stats strips and two fetch layers — and the second copy of each is the
        | one that drifts. One card, one period, ten tabs: the first six ask "is
        | anything wrong and where do I look", the last four answer "what is the
        | figure" for somebody who already knows which figure they want. Same act,
        | two zoom levels.
        |
        | **The statements themselves were not rewritten.** Those four tabs still
        | fetch `GET /reports/*`, which is exactly what they fetched before. A
        | second URL for one answer is a second thing to keep in step.
        |
        | ## READ:LEDGER
        |
        | The workshop's whole financial position on one screen — the same
        | authority the profit & loss needs, and the one an owner holds. The
        | People tab additionally requires READ:STAFF and is stripped by the
        | permission gates without it, because what each person earns is not
        | something the clerk at the counter needs. That is a privacy line rather
        | than an authority one, and widening this card would route round it.
        */
        'insights' => [
            'label' => 'Insights',
            'description' => 'Sales, margin, stock, money owed and the statements',
            'icon' => 'bar-chart',
            'tone' => 'bg-violet-50 text-violet-600',
            'permission' => 'READ:LEDGER',
            'workspace' => true,
            // Built to the §2A flow from the start. Read-mostly under §2A.10, so
            // it opens on its list rather than on a create form — nothing is
            // created here, and "how are we doing" is the only question it is
            // opened to answer.
            'enabled' => true,
        ],

        /*
        | The workshop's own people — M22.
        |
        | Among the day's work rather than beside Settings, and the reason is the
        | attendance sheet: somebody marks the day every morning, which makes this
        | one of the two or three cards opened most often. Payroll is monthly and
        | rides along inside it.
        |
        | ## Why one card and not three
        |
        | Staff, attendance, payroll and advances are four things a workshop does
        | with the same nine people, and splitting them would put four cards on
        | the grid that are only ever opened one after another — "who is on the
        | list", "mark today", "pay the month", "give Ramesh 2,000". They are one
        | module with four sections at level 1, each of which is an ordinary §2A
        | workspace built from the shared renderer. See the note in
        | resources/js/pages/staff.js.
        |
        | ## STAFF, not USERS
        |
        | The two are different questions and the distinction is load-bearing.
        | USERS is who may sign in; STAFF is who is on the payroll. Most of a
        | workshop's fitters have never touched the software, and the owner's son
        | on the counter has a login and no salary. One grant for both would mean
        | that letting somebody add a login also let them read every wage in the
        | building.
        |
        | Only OWNER holds it — DATA_ENTRY has no staff grant at all, which is the
        | one card withheld for privacy rather than for authority.
        */
        'staff' => [
            'label' => 'Staff',
            'description' => 'Employees, attendance, salary and advances',
            'icon' => 'id-card',
            'tone' => 'bg-violet-50 text-violet-600',
            'permission' => 'READ:STAFF',
            'workspace' => true,
            // Built to the §2A flow from the start: four sections, each opening
            // on its own create form with its list behind one switch control.
            'enabled' => true,
        ],

        /*
        | Stored evidence — M14. Among the day's work rather than beside
        | Settings, because photographing an invoice is a thing somebody does at
        | the counter several times a day, not a thing they set up once.
        */
        'uploads' => [
            'label' => 'Uploads',
            'description' => 'Photographed bills and receipts',
            'icon' => 'camera',
            'tone' => 'bg-emerald-50 text-emerald-600',
            'permission' => 'READ:ATTACHMENTS',
            'workspace' => true,
            'enabled' => false,
        ],
    ],

    /*
    | Administration. Opened rarely, and kept in a section of its own so a module
    | somebody uses every day does not sit beside one they use never.
    */
    'admin' => [

        // Platform surface: authority over every workshop. Only ADMIN holds it.
        'tenants' => [
            'label' => 'Workshops',
            'description' => 'Every workshop on the platform',
            'icon' => 'building',
            'tone' => 'bg-blue-50 text-blue-600',
            'permission' => 'READ:TENANTS',
            'workspace' => false,
            'enabled' => false,
        ],

        /*
        | The people, and what they may do.
        |
        | Neither is `workspace`, and the reason differs for each. Users is
        | tenant-scoped at the repository (EloquentUserRepository::scoped()), so
        | a workshop owner reads their own staff and a platform admin reads the
        | platform's — the card is right for both, and membership decides what is
        | behind it rather than whether it is offered. Roles are defined for the
        | whole platform: OWNER holds READ:ROLES and nothing more, so they open
        | the module, read every grant a role carries, and are offered no create
        | form at all (§2A, `canCreate: false`). Writing one is ADMIN's.
        */
        'users' => [
            'label' => 'Users',
            'description' => 'Who may sign in, and as what',
            'icon' => 'user-cog',
            'tone' => 'bg-blue-50 text-blue-600',
            'permission' => 'READ:USERS',
            'workspace' => false,
            // Converted to the §2A flow: opens on the create form, with the
            // directory behind "Show list".
            'enabled' => true,
        ],

        'roles' => [
            'label' => 'Roles',
            'description' => 'What each role is allowed to do',
            'icon' => 'shield',
            'tone' => 'bg-violet-50 text-violet-600',
            'permission' => 'READ:ROLES',
            'workspace' => false,
            // Converted to the §2A flow. Read-only for everybody but ADMIN, who
            // is the only role holding WRITE:ROLES — see the note above.
            'enabled' => true,
        ],

        // The caller's own workshop. Needs membership as well as the grant — a
        // platform admin has no workshop to configure.
        'workspace' => [
            'label' => 'Settings',
            'description' => 'Identity, GSTIN and the financial year',
            'icon' => 'settings',
            'tone' => 'bg-emerald-50 text-emerald-600',
            'permission' => 'READ:WORKSPACE',
            'workspace' => true,
            'enabled' => false,
        ],

        /*
        | Go-live — M11. Gated on UPDATE:WORKSPACE rather than on
        | WRITE:TRANSACTIONS: declaring what the business was worth at go-live is
        | a setup act, not the day job, and a data-entry user holds neither this
        | card nor the endpoint behind it.
        */
        'opening' => [
            'label' => 'Opening balances',
            'description' => 'What the books were worth at go-live',
            'icon' => 'clipboard-list',
            'tone' => 'bg-amber-50 text-amber-600',
            'permission' => 'UPDATE:WORKSPACE',
            'workspace' => true,
            'enabled' => false,
        ],

        /*
        | The trail — M13. Opened when something looks wrong rather than as part
        | of the day's work. Gated on AUDIT, which only OWNER holds: the trail
        | records what a data-entry user did, and reading it is not part of
        | doing it.
        */
        'audit' => [
            'label' => 'History',
            'description' => 'Who changed what, and when',
            'icon' => 'clock',
            'tone' => 'bg-amber-50 text-amber-600',
            'permission' => 'READ:AUDIT',
            'workspace' => true,
            'enabled' => false,
        ],
    ],
];
