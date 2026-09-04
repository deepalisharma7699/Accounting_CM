/**
 * Find a customer or a vendor by typing — the brief's §4.
 *
 * ## What this replaces, and why it had to go
 *
 * The bill form used to load `?per_page=200` of every party into a `<select>`
 * (`bills.js:83`, now gone). That is wrong in two directions at once. A workshop
 * with three hundred customers silently loses the last hundred — the select
 * shows what fitted, with nothing saying so — and a workshop with twelve pays
 * for a request it did not need before the form can open at all. Neither failure
 * announces itself.
 *
 * This asks the server, which is what the server's `search` parameter and its
 * indexes are for. It also means the picker keeps working when the catalogue
 * grows, without anybody revisiting a number.
 *
 * ## The keyboard is the point
 *
 * A counter is worked with one hand on the keyboard and the other on a
 * calculator. Type, arrow down, Enter, and you are on the next field. Escape
 * closes the list without clearing what was typed, because clearing it is the
 * one thing nobody means by Escape.
 *
 * ## What they already owe, at the moment it can still change the decision
 *
 * Choosing the customer is the last point before the lines go on, and it is the
 * only point at which "they are ₹42,000 down already" is worth knowing: after
 * the invoice is posted it is a collections problem, and before the pick there
 * is nobody to ask about. So the position is fetched on the pick and stated in
 * a line under the box.
 *
 * It is fetched **per pick**, not with the search. Searching runs on every
 * debounced keystroke and would compute a position for nine parties nobody
 * chose; the pick happens once per document. `GET /parties/{id}` is the same
 * call a restored draft already makes, and it carries the position — so a
 * correction or a restored draft paints the line with no request at all.
 */

import auth from '../auth-client';
import { describePosition } from './party-position';
import { can } from '../permissions';
import { $, debounce, esc, toast } from '../ui';

/**
 * Mount a party type-ahead into a host element.
 *
 * @param {HTMLElement} host   Empty container; the markup below is written into it.
 * @param {object}      options
 * @param {'customer'|'vendor'} options.role  Which half of the relationship.
 * @param {(party: object|null) => void} options.onSelect
 * @param {string} [options.label]
 */
export function mountPartyPicker(host, { role = 'customer', onSelect = () => {}, label = null } = {}) {
    const heading = label ?? (role === 'vendor' ? 'Vendor' : 'Customer');

    host.innerHTML = `
        <div class="relative" data-party-picker>
            <label class="field-label" for="party-search">${esc(heading)}</label>

            <div class="flex gap-2">
                <div class="relative flex-1">
                    <input id="party-search" type="text" class="field-input pr-9" autocomplete="off"
                           role="combobox" aria-expanded="false" aria-autocomplete="list"
                           aria-controls="party-results"
                           placeholder="Type a name or phone number…" data-party-input>

                    <button type="button"
                            class="absolute right-2 top-1/2 hidden -translate-y-1/2 rounded p-1 text-muted-foreground
                                   transition hover:text-foreground"
                            data-party-clear aria-label="Clear the chosen ${esc(heading.toLowerCase())}">×</button>
                </div>

                <button type="button" class="btn btn-secondary btn-sm shrink-0 hidden" data-party-add
                        data-requires-permission="WRITE:PARTIES">+ Add</button>
            </div>

            <ul id="party-results" role="listbox"
                class="surface absolute z-30 mt-1 hidden max-h-72 w-full overflow-y-auto p-1 shadow-raised"
                data-party-results></ul>

            <p class="mt-1.5 hidden text-[0.8125rem] text-muted-foreground" data-party-chosen></p>

            <p class="mt-1 hidden text-[0.8125rem] font-medium" data-party-position></p>

            <p class="field-error" data-error-for="party_id"></p>
        </div>`;

    const input = $('[data-party-input]', host);
    const results = $('[data-party-results]', host);
    const chosenLine = $('[data-party-chosen]', host);
    const positionLine = $('[data-party-position]', host);
    const clear = $('[data-party-clear]', host);
    const addButton = $('[data-party-add]', host);

    // Shown only where the user may actually create one. The gate is applied here
    // as well as by applyPermissionGates, because this markup is written after
    // that pass has already run over the page.
    addButton.classList.toggle('hidden', !can('WRITE', 'PARTIES'));

    const state = {
        rows: [],
        active: -1,
        selected: null,
        open: false,
        /*
        | Which pick the position on screen belongs to.
        |
        | Two picks in quick succession — chosen, cleared, chosen again — leave
        | two requests in flight, and the slower one is not necessarily the older
        | one. Without this, party A's balance lands under party B's name, which
        | is the one failure here that is worse than showing nothing.
        */
        positionSeq: 0,
        /*
        | Positions already fetched, by party id. A form that clears and re-picks
        | the same customer — every correction does — asks once (§3.6).
        |
        | Per mounted picker rather than module-level: it is a snapshot of a
        | balance, and one that outlived the screen would be shown as current
        | after a receipt had been collected against it.
        */
        positions: new Map(),
    };

    const close = () => {
        state.open = false;
        state.active = -1;
        results.classList.add('hidden');
        input.setAttribute('aria-expanded', 'false');
    };

    const paint = () => {
        results.innerHTML = state.rows.length
            ? state.rows.map((party, index) => `
                <li role="option" id="party-option-${party.id}"
                    aria-selected="${index === state.active}"
                    class="cursor-pointer rounded-md px-3 py-2 text-sm ${index === state.active ? 'bg-accent' : ''}"
                    data-index="${index}">
                    <span class="font-medium text-foreground">${esc(party.name)}</span>
                    ${party.phone ? `<span class="ml-2 text-xs text-muted-foreground">${esc(party.phone)}</span>` : ''}
                    ${party.gstin ? `<span class="ml-2 text-xs text-muted-foreground">${esc(party.gstin)}</span>` : ''}
                </li>`).join('')
            : `<li class="px-3 py-3 text-sm text-muted-foreground">
                   No match. ${can('WRITE', 'PARTIES') ? 'Use “+ Add” to create one.' : ''}
               </li>`;

        state.open = true;
        results.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
    };

    /**
     * Server-side, debounced, and always: there is no local cache to consult,
     * which is the whole reason a workshop's three hundredth customer is
     * findable.
     */
    const search = debounce(async (term) => {
        if (term.trim().length === 0) {
            close();

            return;
        }

        try {
            const { data } = await auth.call(
                `/parties?role=${role}&is_active=1&per_page=10&search=${encodeURIComponent(term)}`
            );

            state.rows = data;
            state.active = data.length ? 0 : -1;
            paint();
        } catch (error) {
            // A failed lookup must not take the bill down with it. The operator
            // can still type and try again, and the toast says why nothing
            // appeared — which an empty dropdown would not.
            toast(error.message, 'error');
            close();
        }
    }, 250);

    /**
     * Put the position line up, take it down, or say it could not be read.
     *
     * `describePosition` returns null for a party who owes nothing *and* for one
     * whose position was never fetched, and both mean "print nothing": a line
     * saying "Nothing outstanding" under every walk-in is noise on the field
     * somebody is trying to leave, and one saying it when nobody looked is a
     * reassurance the picker has not earned.
     */
    const paintPosition = (note) => {
        positionLine.classList.toggle('hidden', note === null);

        if (note === null) {
            positionLine.textContent = '';

            return;
        }

        positionLine.textContent = note.text;
        // Amber is "chase this" everywhere in the application; an advance is the
        // opposite fact and takes the blue the counterparty screens give it.
        positionLine.className = `mt-1 text-[0.8125rem] font-medium ${
            note.tone === 'advance' ? 'text-blue-600' : 'text-amber-600'
        }`;
    };

    /**
     * What the chosen party owes, fetched only if it did not arrive with them.
     *
     * `GET /parties/{id}` carries the position under READ:PARTIES — deliberately,
     * because deciding whether to sell on credit is part of writing the invoice,
     * and a counter clerk who may raise one must be able to see the credit
     * already extended. The *ledger* stays behind READ:LEDGER; this is one
     * number, not the books.
     */
    const loadPosition = async (party) => {
        const seq = ++state.positionSeq;

        if (party === null) {
            paintPosition(null);

            return;
        }

        if (party.outstanding) {
            state.positions.set(party.id, party.outstanding);
            paintPosition(describePosition(party, role));

            return;
        }

        const held = state.positions.get(party.id);

        if (held) {
            paintPosition(describePosition({ outstanding: held }, role));

            return;
        }

        // §3.4 — a surface that is working says so. Muted rather than toned,
        // because it is not yet news either way.
        positionLine.className = 'mt-1 text-[0.8125rem] font-medium text-muted-foreground';
        positionLine.textContent = 'Checking what they owe…';
        positionLine.classList.remove('hidden');

        try {
            const { data } = await auth.call(`/parties/${party.id}`);

            // The pick moved on while this was in flight. Painting now would put
            // one customer's balance under another's name.
            if (seq !== state.positionSeq) return;

            if (data?.outstanding) state.positions.set(party.id, data.outstanding);

            paintPosition(describePosition(data, role));
        } catch {
            if (seq !== state.positionSeq) return;

            /*
            | Said, and not toasted. The bill is writable without this — it is a
            | courtesy on the way past — so a red banner over the counter would
            | be louder than the fact deserves. But it is not silence either:
            | clearing the line would be indistinguishable from "they owe
            | nothing", which is the one wrong answer that reads as reassurance.
            */
            positionLine.className = 'mt-1 text-[0.8125rem] font-medium text-muted-foreground';
            positionLine.textContent = 'Could not check what they owe.';
            positionLine.classList.remove('hidden');
        }
    };

    const select = (party) => {
        state.selected = party;
        input.value = party?.name ?? '';
        chosenLine.classList.toggle('hidden', party === null);
        clear.classList.toggle('hidden', party === null);

        if (party) {
            chosenLine.textContent = [
                party.phone,
                party.gstin ? `GSTIN ${party.gstin}` : null,
                // Said plainly, because it decides whether the invoice carries
                // IGST or CGST+SGST and that is not something to discover after
                // posting.
                party.state_code ? `State ${party.state_code}` : 'No state code — tax will be treated as within the state',
            ].filter(Boolean).join(' · ');
        }

        // Not awaited: the pick must finish now so focus can move to the lines,
        // and the position arrives underneath a moment later.
        loadPosition(party);

        close();
        onSelect(party);
    };

    input.addEventListener('input', (event) => {
        // Typing over a chosen party un-chooses them. A field showing "Rajesh
        // Kum" while a hidden id still says Rajesh Kumar is the shape of bug
        // that bills the wrong customer.
        if (state.selected) select(null);

        search(event.target.value);
    });

    input.addEventListener('keydown', (event) => {
        if (!state.open) {
            if (event.key === 'ArrowDown' && input.value.trim()) search(input.value);

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            state.active = (state.active + step + state.rows.length) % Math.max(state.rows.length, 1);
            paint();

            return;
        }

        if (event.key === 'Enter' && state.rows[state.active]) {
            event.preventDefault();
            select(state.rows[state.active]);

            return;
        }

        // Closes the list, keeps what was typed. Clearing the box is the one
        // thing nobody means by Escape.
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    });

    results.addEventListener('mousedown', (event) => {
        // mousedown, not click: the input's blur would close the list first.
        const row = event.target.closest('[data-index]');

        if (row) {
            event.preventDefault();
            select(state.rows[Number(row.dataset.index)]);
        }
    });

    input.addEventListener('blur', () => setTimeout(close, 120));

    clear.addEventListener('click', () => {
        select(null);
        input.value = '';
        input.focus();
    });

    return {
        /** The chosen party, or null. */
        value: () => state.selected,
        id: () => state.selected?.id ?? null,
        focus: () => input.focus(),
        /** Put somebody in the box — used by the deep links and by autosave. */
        set: (party) => select(party),
        /** Load one by id, for `?party=12` and for a restored draft. */
        load: async (id) => {
            try {
                const { data } = await auth.call(`/parties/${id}`);
                select(data);
            } catch {
                // A party that has since been archived or deleted. Leaving the
                // field empty is honest; pre-filling a name the server will
                // refuse would be worse.
            }
        },
        /** Whatever is in the box right now — a name being typed, most often. */
        term: () => input.value.trim(),

        /*
        | The handler is given the term, because "+ Add" is almost always
        | pressed *after* typing a name that found nothing. Asking somebody to
        | type it a second time is how a save ends up refused for a missing name
        | they had already entered once.
        */
        onAdd: (handler) => addButton.addEventListener('click', () => handler(input.value.trim())),
        role: () => role,
    };
}
