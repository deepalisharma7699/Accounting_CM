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
|   tone         the module's colour, as one `tone-*` class — see below
|   permission   the grant needed to see the card, or null for none
|   workspace    true when the module belongs to a single workshop's books
|   group        which band of the grid the card sits in — see below
|   enabled      false while a module is waiting to be converted — see below
|
| ## `group`
|
| **The grouping is a property of a module, not the shape of this file.** This
| was two nested arrays, `primary` and `admin`, and moving a card between them
| meant moving its whole entry and its comments; the file is flat now and a
| module says which band it belongs to. App\Support\Modules::groups() owns the
| bands and their headings, in the order the grid lays them out, and a card
| appears under its band in the order the modules are declared here.
|
| The bands are what a workshop is *doing*, not what the software is made of:
|
|   selling   the counter — an invoice, a motor booked in, who bought it
|   stock     what comes in and what is on the shelf
|   money     the books: overheads, receipts, the chart, the figures
|   people    the payroll, and who may sign in
|   setup     configured once, and the trail of what was configured
|
| Two cards a workshop opens every day used to sit under one heading with
| fifteen it does not, and administration was a single band of six covering
| wages, logins and the financial year alike. Five smaller bands is the same
| seventeen cards with a heading that says what each one is for.
|
| A module whose `group` is not one Modules::groups() knows about gets no card
| at all. That is caught rather than silent: PagesRenderTest asserts one card
| per enabled module.
|
| ## `tone`
|
| One class — `tone-emerald`, `tone-violet`, `tone-amber`, `tone-blue` — rather
| than the two Tailwind classes (`bg-emerald-50 text-emerald-600`) this used to
| carry. Each declares a `--tone-bg` / `--tone-fg` pair in app.css, and the card
| reads it in more than one place: the band across its head is `--tone-bg` and
| its chip and title are `--tone-fg`. Two colour classes could only ever paint
| the one element they sat on.
|
| ## `enabled`
|
| Seventeen are converted to the §2A flow and on: Sales, Purchase, Items, Stock,
| Customers, Vendors, Insights, Staff, Users, Roles, Settings, Opening balances,
| Expenses, Transactions, Jobs, Accounting and History. Two are off: Uploads and
| Workshops. They still open on a list with a modal create, which is the *only*
| reason each is off — turning one back on is `'enabled' => true` and nothing
| else.
|
| **Off is not unbuilt.** Every module below has a finished backend, a finished
| `pages/*.js`, a fragment view and tests; several are the only way to reach a
| capability the workshop needs (a receipt not tied to a bill, a job card, the
| audit trail). What each one still holds that no enabled card
| covers, and what has already moved to one that is on, is written up in
| docs/hidden-modules.md — read it before converting one, because part of some of
| these screens must *not* be rebuilt (§5.1).
|
| **The rest are scheduled**, go-live first, as C1–C7 in Part E of
| docs/implementation-roadmap.md. **C1 — Settings and Opening balances — is
| done**, so a workshop can go from sign-up to a correct opening trial balance
| without a developer; **C2 — Bills, reduced to expenses — is done**, so its P&L
| has overheads in it; **C3 — Transactions — is done**, so money that arrives
| without a bill has a home and the books have their correction mechanism back;
| **C4 — Jobs — is done**, so the workshop's own trade is on a card and
| `/bills/new` is gone; and **C5 — Accounting, with Ledger merged into it — is
| done**, so a workshop can add an expense head of its own and an accountant can
| be shown a trial balance that reconciles. What is left:
|
|   C6  uploads
|   C7  tenants
|
| **History went on ahead of its step.** It was the read-mostly half of C7 and
| needed no re-flow to speak of — one list, no create, no modal — so it took
| `mountWorkspace(..., { canCreate: false })` and the flag. C7 is now Workshops
| alone.
|
| C5 changed this file by more than a flag: it **removed the `ledger` key**, and
| that is the only removal any of these steps makes. Do not put it back. The
| redirect from `/ledger` is registered by hand in routes/web.php, because the
| loop below only declares one per module the registry still names.
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
        'tone' => 'tone-emerald',
        'permission' => 'READ:TRANSACTIONS',
        'workspace' => true,
        'group' => 'selling',
        // Converted to the §2A flow: opens on the invoice form, with the
        // invoices and credit notes behind "Show list".
        'enabled' => true,
    ],

    /*
    | Expenses — what it costs the workshop to be open. C2.
    |
    | **This module lost everything except one thing, and kept the one that
    | mattered.** It was "Bills": a transaction list spanning sales,
    | purchases, expenses and both kinds of note. Purchases left when
    | Purchase was converted, sales when Sales was, and the list itself is
    | now answered three times over — Sales lists invoices and credit notes,
    | Purchase lists bills and debit notes, and Insights' Day Book lists
    | every posted document, including the journals neither of those shows.
    | Rebuilding any of that here would have been §5.1's mistake.
    |
    | What was only ever here is **writing an expense**. An expense is not a
    | purchase — it is what it costs to be open rather than what was bought
    | to sell — and keeping the two apart is the only reason a P&L can
    | separate gross margin from overheads. `/transactions/expense` has
    | exactly one caller in the front end and it is this module.
    |
    | The **key stays `bills`** while the label says Expenses. The key is the
    | module's address — the fragment route, the shell's lazy-import table
    | and the `#bills` URL — and renaming an address to match a label breaks
    | bookmarks to buy nothing.
    */
    'bills' => [
        'label' => 'Expenses',
        'description' => 'Rent, power and what it costs to be open',
        'icon' => 'receipt',
        'tone' => 'tone-violet',
        'permission' => 'READ:TRANSACTIONS',
        'workspace' => true,
        'group' => 'money',
        'enabled' => true,
    ],

    /*
    | The bench — M19, converted at C4. Gated on WORKSHOP_JOBS rather than on
    | TRANSACTIONS, because a job has nothing in the books until somebody
    | bills it. The split is what keeps "record the day's work" and "post to
    | the ledger" separate authorities, and it survives inside the module:
    | Generate bill additionally needs WRITE:TRANSACTIONS, which the route
    | enforces.
    |
    | Turning this on is what retired `/bills/new`. The counter was the only
    | screen that could raise a workshop bill, and it is now raised from the
    | job it came off — so this card is the last page shell in the
    | application becoming a card, and there are none left.
    */
    'jobs' => [
        'label' => 'Jobs',
        'description' => 'Motors on the bench',
        'icon' => 'wrench',
        'tone' => 'tone-amber',
        'permission' => 'READ:WORKSHOP_JOBS',
        'workspace' => true,
        'group' => 'selling',
        'enabled' => true,
    ],

    /*
    | Transactions — money that arrives or leaves without a document, and the
    | books' own correction mechanism. C3.
    |
    | **This module lost its list and kept the two things only it had.** It
    | was four tabs over every transaction, with the receipt, the payment and
    | the voucher grid behind three buttons on top. Sales lists invoices and
    | credit notes, Purchase lists bills and debit notes, Expenses lists
    | expenses, and Insights' Day Book lists every posted document including
    | the journals none of those show — a fifth copy here would have been
    | four screens answering one question (§5.1).
    |
    | What survived is structural. A customer clearing three invoices with one
    | cheque, or paying on account before anything is raised, had nowhere to
    | go; and the manual journal voucher is what CLAUDE.md names as the
    | correction mechanism for everything else in the books. Without it the
    | only correction available anywhere was reversing a whole document.
    |
    | It also closes M16's hole: `POST /transactions/{id}/allocate` and
    | `GET /transactions/{id}/open-bills` had no caller anywhere in the front
    | end, so a receipt could be settled on the way *in* and never re-pointed
    | afterwards. That screen is the drawer of a posted receipt or payment.
    |
    | Gated on TRANSACTIONS, the same grant Sales, Purchase and Expenses need,
    | so adding this module re-seeds nothing. Re-pointing a receipt
    | additionally wants UPDATE:TRANSACTIONS, which is the honest grant: it
    | writes no journal entry and moves no balance.
    */
    'journal' => [
        'label' => 'Transactions',
        'description' => 'Receipts, payments and journal vouchers',
        'icon' => 'file-text',
        'tone' => 'tone-blue',
        'permission' => 'READ:TRANSACTIONS',
        'workspace' => true,
        'group' => 'money',
        // Converted (C3): three sections, each opening on its own create
        // form with its list behind one switch control — the Staff shape.
        'enabled' => true,
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
        'tone' => 'tone-blue',
        'permission' => 'READ:ITEMS',
        'workspace' => true,
        'group' => 'stock',
        // Converted to the §2A flow: opens on its create form, with the
        // catalogue behind "Show list".
        'enabled' => true,
    ],

    'stock' => [
        'label' => 'Stock',
        'description' => 'What is on the shelf, and what is running out',
        'icon' => 'layers',
        'tone' => 'tone-violet',
        'permission' => 'READ:STOCK',
        'workspace' => true,
        'group' => 'stock',
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
        'tone' => 'tone-blue',
        'permission' => 'READ:TRANSACTIONS',
        'workspace' => true,
        'group' => 'stock',
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
        'tone' => 'tone-emerald',
        'permission' => 'READ:PARTIES',
        'workspace' => true,
        'group' => 'selling',
        // Converted to the §2A flow: opens on the record form, with the
        // customers behind "Show list".
        'enabled' => true,
    ],

    'vendors' => [
        'label' => 'Vendors',
        'description' => 'Who supplies, and what is owed',
        'icon' => 'truck',
        'tone' => 'tone-amber',
        'permission' => 'READ:PARTIES',
        'workspace' => true,
        'group' => 'stock',
        // Converted to the §2A flow: opens on the record form, with the
        // suppliers behind "Show list".
        'enabled' => true,
    ],

    /*
    | Accounting — C5, and the one entry that swallowed another.
    |
    | There was a `ledger` key beside this one, carrying the trial balance
    | and one account's running ledger. They were the same question at two
    | zoom levels, so two cards would both have answered "what does this
    | account stand at" and needed two period pickers and two trial-balance
    | renderers between them (§5.1). The key is gone; the trial balance is
    | the second view of this card, and `/ledger` still redirects — see
    | routes/web.php, which registers that one by hand now.
    |
    | Gated on READ:ACCOUNTS, which is authority over the chart. Every figure
    | inside additionally needs READ:LEDGER, and a holder of the first alone
    | gets the chart with no figures on it at all.
    */
    'accounts' => [
        'label' => 'Accounting',
        'description' => 'The chart of accounts, balances and the trial balance',
        'icon' => 'book-open',
        'tone' => 'tone-emerald',
        'permission' => 'READ:ACCOUNTS',
        'workspace' => true,
        'group' => 'money',
        'enabled' => true,
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
        'tone' => 'tone-violet',
        'permission' => 'READ:LEDGER',
        'workspace' => true,
        'group' => 'money',
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
        'tone' => 'tone-violet',
        'permission' => 'READ:STAFF',
        'workspace' => true,
        'group' => 'people',
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
        'tone' => 'tone-emerald',
        'permission' => 'READ:ATTACHMENTS',
        'workspace' => true,
        'group' => 'money',
        'enabled' => false,
    ],

    // Platform surface: authority over every workshop. Only ADMIN holds it.
    'tenants' => [
        'label' => 'Workshops',
        'description' => 'Every workshop on the platform',
        'icon' => 'building',
        'tone' => 'tone-blue',
        'permission' => 'READ:TENANTS',
        'workspace' => false,
        'group' => 'setup',
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
        'tone' => 'tone-blue',
        'permission' => 'READ:USERS',
        'workspace' => false,
        'group' => 'people',
        // Converted to the §2A flow: opens on the create form, with the
        // directory behind "Show list".
        'enabled' => true,
    ],

    'roles' => [
        'label' => 'Roles',
        'description' => 'What each role is allowed to do',
        'icon' => 'shield',
        'tone' => 'tone-violet',
        'permission' => 'READ:ROLES',
        'workspace' => false,
        'group' => 'people',
        // Converted to the §2A flow. Read-only for everybody but ADMIN, who
        // is the only role holding WRITE:ROLES — see the note above.
        'enabled' => true,
    ],

    // The caller's own workshop. Needs membership as well as the grant — a
    // platform admin has no workshop to configure.
    'workspace' => [
        'label' => 'Settings',
        'description' => 'Identity, the financial year and trading rules',
        'icon' => 'settings',
        'tone' => 'tone-emerald',
        'permission' => 'READ:WORKSPACE',
        'workspace' => true,
        'group' => 'setup',
        // Converted (C1). One record, so one surface: the module declares
        // only `data-ws-list` and mounts with `canCreate: false`, landing
        // straight on the form with no switch control to paint.
        'enabled' => true,
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
        'tone' => 'tone-amber',
        'permission' => 'UPDATE:WORKSPACE',
        'workspace' => true,
        'group' => 'setup',
        // Converted (C1): opens on the declaration, with every import ever
        // run behind "Show list". The two-button discipline is unchanged.
        'enabled' => true,
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
        'tone' => 'tone-amber',
        'permission' => 'READ:AUDIT',
        'workspace' => true,
        'group' => 'setup',
        // Converted: read-mostly (§2A.10), so it opens on its list with no
        // switch control. That is not a choice that could be widened —
        // there is no POST, PATCH or DELETE anywhere in its API group.
        'enabled' => true,
    ],
];
