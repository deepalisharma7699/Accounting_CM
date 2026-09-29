/**
 * Every dropdown in the application, searchable — once, for all of them.
 *
 * ## Why this is one file and not a pattern each module follows
 *
 * There are fifty-odd `<select>` elements in this product and more arrive with
 * every module: the category on the item form, the expense account on a bill,
 * the account on a journal line, the designation on an employee, the unit on a
 * catalogue attribute, and a filter on almost every toolbar. A workshop with
 * sixty accounts or two hundred units cannot find a row in a native select — it
 * scrolls, it has no search beyond first-letter typeahead, and on a phone it is
 * whatever the platform decides to show.
 *
 * Converting them one screen at a time would have meant a second control to
 * remember on every new form, and the failure would be silent in exactly the way
 * §3.8 describes: the screen that forgot looks fine until somebody with a long
 * list opens it. So this is applied *centrally* — `initSearchableSelects()` runs
 * one pass over the document and then watches for markup arriving, which is what
 * a lazily-mounted module, a drawer, a repeated journal line and a dialog all
 * are. A module written next week inherits it by writing an ordinary `<select>`.
 *
 * ## The native select stays, and it stays the source of truth
 *
 * This is the whole of why it can be applied everywhere at once without
 * auditing a hundred call sites. The `<select>` is not replaced — it is moved
 * into a wrapper, taken off the screen and left in the form, so every existing
 * line of code keeps working untouched:
 *
 *   - `select.value`, `select.selectedOptions[0]`, `select.options[0]`
 *   - `select.innerHTML = …` to repaint the options from `/items/meta`
 *   - `form.elements[name]` and the `aria-invalid` that `showFormErrors` sets
 *   - `addEventListener('change', …)`, including delegated listeners
 *   - `form.reset()`, `select.disabled = true`, `classList.toggle('hidden')`
 *
 * What is drawn instead is a button and a panel that *read* the select and write
 * back to it. Three directions of change have to be followed, and each one is a
 * screen that would otherwise be wrong in a way nobody notices:
 *
 *   - **Options change** (`innerHTML` from a meta load) — a MutationObserver on
 *     the select's children. It fires as a microtask, so the `select.value =
 *     held` that every repaint does afterwards has already run by the time the
 *     label is read.
 *   - **Value changes with no DOM change** (`filter.value = '1'`) — the `value`
 *     and `selectedIndex` properties are shadowed on the instance, because
 *     nothing observable happens when they are assigned.
 *   - **Chrome changes** — `disabled`, `aria-invalid`, `aria-label` and the
 *     `hidden` class that Accounting's view switch toggles are all mirrored onto
 *     the button. Mirroring `hidden` is not cosmetic: the class is put on the
 *     *select*, and without this the filter would be hidden and its button left
 *     standing on the toolbar.
 *
 * ## The search box appears when there is something to search
 *
 * A three-option "Active / Archived / Both" does not need a text box over it,
 * and one there would be noise on every toolbar in the product. So the input is
 * always present and always focused — typing filters whatever the list is — but
 * it is only *shown* from eight options up, and revealed the moment somebody
 * types into a short list anyway. Nothing is unsearchable; nothing short carries
 * furniture it has no use for.
 */

import { esc } from '../ui';

/** From this many options up, the search box is drawn rather than only focused. */
const SEARCH_FROM = 8;

/** The panel flips above the trigger when there is less than this below it. */
const ROOM_NEEDED = 260;

/** Breathing room kept between the panel and whatever would clip it. */
const GUTTER = 8;

/** However tight it is, the list is never shorter than about three rows. */
const MIN_LIST = 120;

/** Instances, so a sync can be found from the select a caller already holds. */
const instances = new WeakMap();

/** Forms whose `reset` is already watched — one listener each, not one per select. */
const watchedForms = new WeakSet();

/** At most one panel is open, and opening one closes the last. */
let openInstance = null;

let booted = false;

const valueDescriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
const indexDescriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');

const CARET = `
    <svg class="ss-caret" width="14" height="14" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>`;

let uid = 0;

/* -------------------------------------------------------------------------
 | Which selects this applies to
 | ---------------------------------------------------------------------- */

/**
 * A multiple-select is a different control with different keyboard rules, and a
 * `data-plain-select` is an explicit opt-out for anywhere a native menu is the
 * right answer. Everything else is enhanced.
 */
function eligible(select) {
    return select instanceof HTMLSelectElement
        && !select.multiple
        && !select.hasAttribute('data-plain-select')
        && !select.hasAttribute('data-ss-native');
}

/* -------------------------------------------------------------------------
 | Reading the select
 | ---------------------------------------------------------------------- */

/**
 * The options as rows, keeping the select's own order and its optgroup
 * headings — the journal's account picker groups by account type, and a flat
 * list of sixty accounts is the thing it was grouped to avoid.
 *
 * An `option[hidden]` is left out for the same reason the browser leaves it
 * out, and a disabled one is shown but cannot be chosen: a placeholder that has
 * been disabled is still the label for "nothing chosen yet".
 */
function rowsOf(select) {
    return Array.from(select.options)
        .filter((option) => !option.hidden)
        .map((option) => ({
            index: option.index,
            label: option.textContent.replace(/\s+/g, ' ').trim(),
            value: option.value,
            disabled: option.disabled,
            group: option.parentElement instanceof HTMLOptGroupElement
                ? option.parentElement.label
                : null,
        }));
}

/** Every term has to appear somewhere in the label, so "cro 5" finds "Crompton 5 HP". */
function matches(row, terms) {
    if (!terms.length) return true;

    const haystack = `${row.label} ${row.group ?? ''}`.toLowerCase();

    return terms.every((term) => haystack.includes(term));
}

/* -------------------------------------------------------------------------
 | Mounting one
 | ---------------------------------------------------------------------- */

function enhance(select) {
    if (!eligible(select)) return;

    const id = `ss-${++uid}`;

    const wrapper = document.createElement('div');
    wrapper.className = 'ss';
    wrapper.setAttribute('data-searchable-select', '');

    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);
    select.setAttribute('data-ss-native', '');
    // Out of the tab order: the button is what Tab reaches now. It stays
    // programmatically focusable, which is what makes a `<label for>` pointing
    // at it still land somewhere sensible — see the focus handler below.
    select.setAttribute('tabindex', '-1');

    wrapper.insertAdjacentHTML('beforeend', `
        <button type="button" class="ss-trigger" id="${id}-trigger"
                aria-haspopup="listbox" aria-expanded="false" aria-controls="${id}-list">
            <span class="ss-label" data-ss-label></span>
            ${CARET}
        </button>

        <div class="ss-panel" data-ss-panel hidden>
            <div class="ss-search" data-ss-search>
                <input type="text" class="ss-search-input" data-ss-input autocomplete="off"
                       spellcheck="false" placeholder="Search…" aria-label="Search the options"
                       role="combobox" aria-expanded="true" aria-controls="${id}-list"
                       aria-autocomplete="list">
            </div>
            <ul class="ss-options" id="${id}-list" role="listbox" data-ss-list></ul>
        </div>`);

    const trigger = wrapper.querySelector('.ss-trigger');
    const label = wrapper.querySelector('[data-ss-label]');
    const panel = wrapper.querySelector('[data-ss-panel]');
    const searchRow = wrapper.querySelector('[data-ss-search]');
    const input = wrapper.querySelector('[data-ss-input]');
    const list = wrapper.querySelector('[data-ss-list]');

    const self = {
        select,
        wrapper,
        trigger,
        panel,
        rows: [],
        shown: [],
        active: -1,
        open: false,
        sync,
    };

    instances.set(select, self);

    /* --- painting ---------------------------------------------------- */

    /** The button's face: whatever the select currently has chosen. */
    function syncLabel() {
        const chosen = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        const text = chosen ? chosen.textContent.replace(/\s+/g, ' ').trim() : '';

        label.textContent = text || (select.options.length ? '' : 'Select…');
        label.classList.toggle('ss-label--empty', text === '');
        trigger.title = text;
    }

    /**
     * Everything the select says about itself that is now the button's to show.
     *
     * `hidden` is moved to the wrapper rather than copied to the button: the
     * class is toggled on the select by the code that switches Accounting
     * between its chart and its trial balance, and a button left behind on a
     * toolbar is the visible half of a filter that no longer applies.
     */
    function syncChrome() {
        const classes = select.className.split(/\s+/).filter(Boolean);
        const isHidden = classes.includes('hidden');

        trigger.className = ['ss-trigger', ...classes.filter((name) => name !== 'hidden')].join(' ');
        wrapper.toggleAttribute('data-ss-hidden', isHidden || select.hidden);

        trigger.disabled = select.disabled;

        const invalid = select.getAttribute('aria-invalid');
        if (invalid) trigger.setAttribute('aria-invalid', invalid);
        else trigger.removeAttribute('aria-invalid');

        const described = select.getAttribute('aria-label');
        if (described) trigger.setAttribute('aria-label', described);
        else trigger.removeAttribute('aria-label');
    }

    function sync() {
        syncLabel();
        syncChrome();

        if (self.open) paint();
    }

    /** The filtered list, with the chosen row marked and one row active. */
    function paint() {
        const terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);

        self.shown = self.rows.filter((row) => matches(row, terms));

        if (!self.shown.some((row) => row.index === self.active)) {
            const chosen = self.shown.find((row) => row.index === select.selectedIndex);
            const first = self.shown.find((row) => !row.disabled);

            self.active = (chosen ?? first)?.index ?? -1;
        }

        if (!self.shown.length) {
            list.innerHTML = '<li class="ss-empty">No match.</li>';

            return;
        }

        let group = null;

        list.innerHTML = self.shown.map((row) => {
            let heading = '';

            if (row.group !== group) {
                group = row.group;
                heading = group ? `<li class="ss-group" role="presentation">${esc(group)}</li>` : '';
            }

            const chosen = row.index === select.selectedIndex;

            return `${heading}
                <li role="option" class="ss-option${chosen ? ' ss-option--chosen' : ''}${
                    row.index === self.active ? ' ss-option--active' : ''
                }${row.disabled ? ' ss-option--disabled' : ''}"
                    id="${id}-opt-${row.index}" aria-selected="${chosen}"
                    ${row.disabled ? 'aria-disabled="true"' : ''}
                    data-index="${row.index}">${esc(row.label) || '&nbsp;'}</li>`;
        }).join('');

        paintActive();
    }

    /**
     * Move the highlight without rebuilding the list.
     *
     * A full repaint would replace the very element the pointer is over, and the
     * mousemove that lands on its replacement would repaint it again.
     */
    function paintActive() {
        list.querySelectorAll('.ss-option').forEach((option) => {
            option.classList.toggle('ss-option--active', Number(option.dataset.index) === self.active);
        });

        const active = list.querySelector('.ss-option--active');

        if (active) input.setAttribute('aria-activedescendant', active.id);
        else input.removeAttribute('aria-activedescendant');

        active?.scrollIntoView({ block: 'nearest' });
    }

    /* --- opening and closing ------------------------------------------ */

    /** Show the search box that a short list did not draw, keyboard and all. */
    function reveal() {
        if (!searchRow.classList.contains('ss-search--quiet')) return;

        searchRow.classList.remove('ss-search--quiet');
        input.inputMode = 'text';

        // The panel just grew by the height of that row; the list gives it back.
        if (self.open) fit();
    }

    /**
     * What will actually clip this panel.
     *
     * Most of these selects are on a level-2 surface, and a drawer's body and a
     * dialog's body both scroll — which means an absolutely positioned panel is
     * cut off at *their* edge, not the window's. Measuring against the viewport
     * is how a list opens downwards into two hundred pixels of drawer and is
     * shown with three rows in it.
     */
    function clipper() {
        let node = trigger.parentElement;

        while (node && node !== document.body) {
            if (/(auto|scroll|hidden)/.test(getComputedStyle(node).overflowY)) {
                return node.getBoundingClientRect();
            }

            node = node.parentElement;
        }

        return null;
    }

    /** Which way the panel opens, and how many rows it may show. */
    function fit() {
        const box = trigger.getBoundingClientRect();
        const clip = clipper();
        const top = Math.max(0, clip ? clip.top : 0);
        const bottom = Math.min(window.innerHeight, clip ? clip.bottom : window.innerHeight);

        const below = bottom - box.bottom - GUTTER;
        const above = box.top - top - GUTTER;
        const up = below < ROOM_NEEDED && above > below;

        panel.classList.toggle('ss-panel--up', up);

        // The search row is measured rather than assumed: it is not drawn at all
        // on a short list, and the rows get its height back when it is not.
        const room = Math.max(up ? above : below, MIN_LIST) - searchRow.offsetHeight;

        list.style.maxHeight = `${Math.max(MIN_LIST, Math.min(room, window.innerHeight * 0.6))}px`;
    }

    function close({ focus = false } = {}) {
        if (!self.open) return;

        self.open = false;
        if (openInstance === self) openInstance = null;
        panel.hidden = true;
        panel.classList.remove('ss-panel--up');
        trigger.setAttribute('aria-expanded', 'false');
        input.value = '';

        if (focus) trigger.focus();
    }

    function open(seed = '') {
        if (self.open || select.disabled) return;

        openInstance?.close();

        self.rows = rowsOf(select);
        self.active = select.selectedIndex;
        self.open = true;
        openInstance = self;

        /*
        | The box is drawn only where there is a list worth searching, and is
        | revealed as soon as somebody types into a short one anyway.
        |
        | `inputMode` goes with it: the input is focused either way, so without
        | this a three-option filter tapped on a phone would raise the on-screen
        | keyboard over a list that fits on the screen already. A physical
        | keyboard still types into it, which is what keeps the short lists
        | searchable rather than only tappable.
        */
        const quiet = self.rows.length < SEARCH_FROM;

        searchRow.classList.toggle('ss-search--quiet', quiet);
        input.inputMode = quiet ? 'none' : 'text';

        input.value = seed;
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');

        /*
        | Above or below, and how tall — decided against the room actually there.
        |
        | The control is brought into view first and the measurement taken
        | after: `nearest` moves nothing that is already on screen, and reading
        | the rectangle of a trigger half off the bottom would answer "no room
        | below" about a control the user is about to be scrolled to.
        */
        if (seed) reveal();

        trigger.scrollIntoView({ block: 'nearest' });
        fit();

        paint();
        input.focus();
    }

    /**
     * Write the choice back to the select and say so the way the select would.
     *
     * Both events, in the browser's own order: a native pick fires `input` and
     * then `change`, and the bill form's draft autosave listens for the first
     * where every filter listens for the second.
     */
    function choose(index) {
        const option = select.options[index];

        if (!option || option.disabled) return;

        const changed = select.selectedIndex !== index;

        if (changed) {
            select.selectedIndex = index;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        sync();
        close({ focus: true });
    }

    function move(step) {
        const usable = self.shown.filter((row) => !row.disabled);

        if (!usable.length) return;

        const at = usable.findIndex((row) => row.index === self.active);
        const next = usable[Math.min(Math.max(at + step, 0), usable.length - 1)] ?? usable[0];

        self.active = next.index;
        paintActive();
    }

    /* --- events -------------------------------------------------------- */

    trigger.addEventListener('click', (event) => {
        // Not stopped: a click on a native select bubbled too, and the screens
        // that close a popover on an outside click are counting on it.
        event.preventDefault();

        if (self.open) close({ focus: true });
        else open();
    });

    trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();

            return;
        }

        // Typing straight at the closed control searches, which is what a native
        // select's first-letter jump was reaching for and could not finish.
        if (event.key.length === 1 && !event.metaKey && !event.ctrlKey && !event.altKey) {
            event.preventDefault();
            open(event.key);
        }
    });

    input.addEventListener('input', () => {
        if (input.value) reveal();

        paint();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            move(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            move(-1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(self.active);
        } else if (event.key === 'Escape') {
            // One press closes the list and goes no further: the shell unwinds a
            // level per press (§2A.9), and a dropdown open over a drawer is the
            // innermost thing on the screen.
            event.preventDefault();
            event.stopPropagation();
            close({ focus: true });
        } else if (event.key === 'Tab') {
            close();
        }
    });

    list.addEventListener('mousedown', (event) => {
        // Before the blur, so the pick is not raced by the panel closing.
        const option = event.target.closest('.ss-option');

        if (!option) return;

        event.preventDefault();
        choose(Number(option.dataset.index));
    });

    list.addEventListener('mousemove', (event) => {
        const option = event.target.closest('.ss-option');
        const index = option ? Number(option.dataset.index) : -1;

        if (index < 0 || index === self.active || option.classList.contains('ss-option--disabled')) return;

        self.active = index;
        paintActive();
    });

    // A `<label for>` still points at the select, which is where its id is. The
    // click focuses it; this hands that focus on to the control the user can see.
    select.addEventListener('focus', () => {
        if (!self.open) trigger.focus();
    });

    /* --- staying in step with the select -------------------------------- */

    const observer = new MutationObserver(sync);

    observer.observe(select, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['class', 'hidden', 'disabled', 'required', 'aria-invalid', 'aria-label', 'selected', 'value'],
    });

    /*
    | Assigning `select.value` changes nothing a MutationObserver can see, and
    | the filters do exactly that — `$('#filter-archived').value = '1'`. So the
    | two properties that move the selection are shadowed on this instance, the
    | prototype's own accessor doing the work underneath.
    */
    if (valueDescriptor && indexDescriptor) {
        Object.defineProperty(select, 'value', {
            configurable: true,
            enumerable: false,
            get() { return valueDescriptor.get.call(this); },
            set(next) { valueDescriptor.set.call(this, next); sync(); },
        });

        Object.defineProperty(select, 'selectedIndex', {
            configurable: true,
            enumerable: false,
            get() { return indexDescriptor.get.call(this); },
            set(next) { indexDescriptor.set.call(this, next); sync(); },
        });
    }

    /*
    | `form.reset()` moves the selection without touching a property or an
    | attribute, and every module's create form calls it after a save. The
    | listener is on the form rather than the document so it still arrives while
    | the form is detached — which is exactly where §2A.2 keeps it while the list
    | is up. The default action runs after dispatch, so the read waits a turn.
    */
    const form = select.form;

    if (form && !watchedForms.has(form)) {
        watchedForms.add(form);
        form.addEventListener('reset', () => {
            setTimeout(() => {
                form.querySelectorAll('[data-ss-native]').forEach((other) => instances.get(other)?.sync());
            }, 0);
        });
    }

    self.close = close;

    sync();
}

/* -------------------------------------------------------------------------
 | Applying it
 | ---------------------------------------------------------------------- */

/**
 * Enhance every eligible select in a subtree.
 *
 * Safe to call on a detached node — `shell.js` mounts a module's markup that
 * way, before it is on screen — and safe to call twice, since an enhanced
 * select carries `data-ss-native` and is skipped.
 */
export function enhanceSelects(root = document) {
    if (!root || typeof root.querySelectorAll !== 'function') return;

    if (root instanceof HTMLSelectElement) {
        enhance(root);

        return;
    }

    root.querySelectorAll('select:not([data-ss-native])').forEach(enhance);
}

/**
 * One pass over the document, and then a watch on everything that arrives.
 *
 * The watch is the point. Selects are written by Blade fragments fetched on a
 * card click, by drawers, by a repeated journal line, by the attribute fields a
 * category asks for and by a dialog in the layout — and a convention that each
 * of those remembers to call `enhanceSelects()` fails silently, one site at a
 * time, which is the reasoning §3.8 records about the activity bar. Anything
 * built detached is caught the moment its ancestor is attached, because that is
 * an added node like any other.
 */
export function initSearchableSelects() {
    if (booted) return;

    booted = true;

    enhanceSelects(document);

    new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;

                enhanceSelects(node);
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    // Anywhere outside the open panel closes it, including another control on
    // the same form. Capture, so a screen that stops the click from bubbling
    // does not leave a panel standing over it.
    document.addEventListener('mousedown', (event) => {
        if (!openInstance) return;

        if (!openInstance.wrapper.isConnected || !openInstance.wrapper.contains(event.target)) {
            openInstance.close();
        }
    }, true);

    // The shell caches a module's root detached, so a panel can be left open on
    // a node that is no longer in the document.
    window.addEventListener('blur', () => openInstance?.close());
}
