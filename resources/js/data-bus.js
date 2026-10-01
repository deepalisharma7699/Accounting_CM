/**
 * What has just moved, said once, to whoever is holding a copy of it.
 *
 * ## The problem this exists for
 *
 * The shell holds every module it has opened, *detached but alive*
 * (`shell.js`), and the workspace fetches a list on the first Show and holds it
 * from then on (§2A.7). Both are deliberate — that is what makes returning to a
 * module instant and what stops one module paying for another's data. Together
 * they mean a module's rows are a snapshot of whenever it was last looked at.
 *
 * That is correct right up to the moment a *different* module changes the same
 * underlying fact. Record a count in Stock and the Items list is still showing
 * yesterday's quantity; post a sale and the shelf on the Stock screen is a sale
 * out of date. Before this file the only cure was reloading the whole page,
 * which is the one thing §3.2 forbids as an answer to anything.
 *
 * So: a write says what kind of fact it touched, and the screens holding a copy
 * of that fact mark themselves stale. **Nothing here fetches.** A stale module
 * that is off screen stays off screen and stays stale; it refetches when it is
 * next actually looked at, which is what keeps §7.2 true — opening one module
 * must not load another module's data, and neither must writing to one.
 *
 * ## Why the announcing happens in `auth-client`
 *
 * Because the failure being fixed is a *missed* invalidation, and a convention
 * that every write site must remember to call `announce()` fails in exactly that
 * way — silently, one call site at a time, with a stale screen months later and
 * nothing to connect it back. Every write in the application already goes
 * through `auth.call()`, so that is where the announcement is made, from the one
 * table below (§4.4).
 *
 * The table is deliberately generous. A pure receipt moves no stock, but it is
 * listed under `transactions` all the same: a false positive costs one refetch
 * the next time somebody opens Stock, and a false negative is the bug this file
 * exists to remove. Err towards the cheap mistake.
 *
 * ## Adding to it
 *
 * A new module that holds rows subscribes — `refreshOn` on `mountWorkspace` for
 * anything with a level-1 list, `onChange` directly for anything else. A new
 * endpoint that writes gets a row in {@link WRITES} if it is not already covered
 * by a prefix. Do not add a per-module refresh button, a poll, or a second bus.
 */

/**
 * The kinds of fact a screen can hold a copy of.
 *
 * `kinds` is what the bench asks about a thing on it — held by the Jobs module,
 * which is also the only thing that writes it. Named here anyway, because the
 * announcement is what has to be right: a screen that comes to hold a copy of
 * it later only has to subscribe.
 *
 * `ledger` is held by Opening balances, whose position is a copy of the books
 * and of the go-live date on the settings screen. `staff` has no subscriber yet
 * — the module that would hold a copy of it refreshes its own sections already.
 * It is named here anyway, because the announcement is what has to be right: a
 * write that reports nothing is invisible, and the module that comes to hold
 * that data later only has to subscribe.
 */
const RESOURCES = ['stock', 'items', 'transactions', 'parties', 'ledger', 'staff', 'kinds'];

/** resource -> the handlers that want to know. */
const listeners = new Map(RESOURCES.map((resource) => [resource, new Set()]));

/**
 * The POSTs that write nothing.
 *
 * A verb is not a promise. These take a body — a whole document's lines, a
 * month's attendance — so they cannot be GETs, and every one of them is a
 * *question*: what would this bill come to, what would this run pay. Treating
 * them as writes is not a harmless over-count either, because the bill form asks
 * `transactions/preview` on a debounce as somebody types: every few keystrokes
 * would have announced that the shelf had moved, refetched the position of every
 * line on the document, and marked every held list in the application stale.
 */
const READ_ONLY_POSTS = [
    /^\/transactions\/preview(\?|$)/,
    /^\/opening-balances\/preview(\?|$)/,
    /^\/staff\/payroll\/preview(\?|$)/,
];

/**
 * Which resources a non-GET call to a path has moved.
 *
 * Prefix-matched and first-match-wins, so the order matters where one path is a
 * prefix of another. Anything unlisted — `/auth`, `/permissions`, `/insights` —
 * announces nothing, which is right: nobody holds a copy of those.
 */
const WRITES = [
    /*
    | The posting engine, in all its forms: a bill, a return, a reversal, a
    | correction, a receipt, a payment, a stock adjustment. They share a prefix
    | because they share consequences — every one of them can move the shelf,
    | the books and what a counterparty owes, and picking them apart by URL
    | would be re-deciding per route what the engine already decides per posting.
    */
    [/^\/transactions(\/|\?|$)/, ['transactions', 'stock', 'parties', 'ledger']],

    /*
    | Parts issued to a job leave the shelf like anything else — and the same
    | prefix raises the invoice for them. `{job}/bill` posts a sale through the
    | ordinary engine, so it moves what the customer owes and the ledger with it;
    | listing only the first two here meant a workshop bill left a held Customers
    | list and a held statement a repair out of date.
    */
    [/^\/workshop-jobs(\/|\?|$)/, ['transactions', 'stock', 'parties', 'ledger']],

    /*
    | The bench's own vocabulary. Listed **before** nothing and after nothing in
    | particular — it shares no prefix with `workshop-jobs` — but it is here
    | rather than folded into the row above because it invalidates a different
    | thing: no figure moves, and what goes stale is the question set the intake
    | form draws. Announced all the same, for §3.7's reason: the failure being
    | guarded against is a *missed* invalidation, and a convention that each
    | write site remembers to announce fails silently, one site at a time.
    */
    [/^\/job-kinds(\/|\?|$)/, ['kinds']],

    // A new variant is a new row on the stock screen, at a position of zero.
    [/^\/items(\/|\?|$)/, ['items', 'stock']],

    // The catalogue's vocabulary — a renamed category retitles rows on both
    // screens that group by it.
    [/^\/item-categories(\/|\?|$)/, ['items']],
    [/^\/item-brands(\/|\?|$)/, ['items']],
    [/^\/units(\/|\?|$)/, ['items']],

    [/^\/parties(\/|\?|$)/, ['parties']],

    // What the workshop was worth on day one lands in stock, in the ledger and
    // against the parties it was owed by.
    [/^\/opening-balances(\/|\?|$)/, ['stock', 'parties', 'ledger']],

    // Payroll and advances post vouchers; the employee list is its own thing.
    [/^\/staff(\/|\?|$)/, ['staff', 'ledger']],

    [/^\/accounts(\/|\?|$)/, ['ledger']],

    /*
    | Which cards sit at the top of home. Listed *ahead* of `/workspace` below,
    | and the order is the whole reason this row exists: first match wins, so
    | without it a star would take that row and announce that `ledger` had
    | moved — marking every held statement and every Insights panel stale on
    | each click, for a change that moves no figure and touches no period.
    |
    | Announcing nothing is the right answer rather than a cheap one: this is
    | the exception the "err towards the false positive" rule above is written
    | against, because nothing anywhere holds a copy of the favourites but the
    | home grid that just changed them.
    */
    [/^\/workspace\/favourites(\/|\?|$)/, []],

    /*
    | The workshop's own settings. It posts nothing, and it is listed anyway:
    | the financial year and the timezone define the period every statement and
    | every Insights panel is measured over, and `books_start_date` is the day
    | the books begin — so saving this screen can make a held report wrong
    | without a single figure having moved.
    */
    [/^\/workspace(\/|\?|$)/, ['ledger']],
];

/**
 * Tell everyone holding a copy of these facts that they are behind.
 *
 * Handlers are called synchronously and in subscription order. One that throws
 * is contained: a module failing to mark itself stale is a stale screen, and it
 * must not also break the write that was reporting the change.
 */
export function announce(...resources) {
    new Set(resources.flat()).forEach((resource) => {
        listeners.get(resource)?.forEach((handler) => {
            try {
                handler(resource);
            } catch {
                // As above. There is nothing useful to do with it here, and the
                // caller is a completed write that succeeded.
            }
        });
    });
}

/**
 * Be told when one of these kinds of fact changes anywhere in the application.
 *
 * @param {string|string[]} resources
 * @param {(resource: string) => void} handler
 * @returns {() => void} stop listening
 */
export function onChange(resources, handler) {
    const wanted = [resources].flat().filter((resource) => listeners.has(resource));

    wanted.forEach((resource) => listeners.get(resource).add(handler));

    return () => wanted.forEach((resource) => listeners.get(resource).delete(handler));
}

/**
 * The hook `auth-client` calls after a write comes back successfully.
 *
 * Reads are silent — a GET has moved nothing, and announcing on one would put
 * this in a loop with the screens that refetch when they hear it.
 */
export function announceWrite(path, method = 'GET') {
    if (method.toUpperCase() === 'GET') return;
    if (READ_ONLY_POSTS.some((pattern) => pattern.test(path))) return;

    const match = WRITES.find(([pattern]) => pattern.test(path));

    if (match) announce(match[1]);
}
