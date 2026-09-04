{{--
| Adding a counterparty, wherever somebody is standing — the brief's §4.
|
| One form for both jobs it has to do, because they are the same job:
|
|   * the **quick add** from a picker, mid-document. Somebody is halfway through
|     a purchase bill, the supplier is not on the books, and the whole value of
|     this is that they do not lose the bill to go and create one.
|   * the **full record** on the Customers and Vendors screens, which also edits.
|
| Lifted out of `bills/new.blade.php` and `partials/counterparty-page.blade.php`,
| where the two were separate copies of one form — with the fields, the GSTIN
| hint and the role rules maintained twice. The counterparty copy had
| client-side validation and role checkboxes; the bill counter's had neither, so
| a mistyped GSTIN at the counter only failed after a round trip. That is the
| drift §4.4 and §5.1 exist to prevent, and it is why this is a partial with one
| caller per screen rather than a third copy.
|
| ## Why the extra fields are hidden rather than absent
|
| The parts marked `data-quick-party-full` are the ones only the record screens
| need: their email, free notes, and where opening balances actually belong. A
| drawer opened mid-bill shows the four fields needed to raise one and nothing
| else, because every field past those is a reason to abandon what they were
| doing. The markup is one form all the same — two would be two places for a rule
| to be added to only one of.
|
| ## There is no role field
|
| Not on either shape. What this form writes is decided by where it was opened
| from: the Vendors module writes a vendor, the Customers module a customer, and
| a picker on a purchase bill writes the vendor that document needs. Somebody
| adding a supplier is not making a modelling decision, and a pair of checkboxes
| asked them to.
|
| The counterparty who is *both* is still one record with one combined ledger —
| that is the whole point of the parties table — but it is arrived at by being
| told, not by being asked. Saving a name that is already on the books offers to
| mark the existing record with this role as well, which is the moment the
| question actually means something. See components/quick-party.js.
|
| ## Why a drawer and not a modal
|
| It opens over a surface that is already open: a level-1 create form, or a
| level-1 list. A second dialog stacked on the first reads as an error (§2.2),
| and one record — add, edit, view — is exactly what level 2 is for.
|
| ## The form has two frames, not two copies
|
| On a converted counterparty module the *create* form is level 1 itself: the
| module opens on it (§2A.1), so the form node is moved into the workspace's
| slot rather than written out a second time there. Only the frame differs — a
| dialog needs a title bar, a close button and a Cancel, an inline form needs
| none of those — so those parts are marked `data-form-chrome="modal"` and the
| level-1 footer `data-form-chrome="inline"`, and `adoptForm()` in
| resources/js/workspace.js shows whichever set matches where the form is going.
| Editing is always the drawer: it is one record over a list (level 2).
|
| The ids are prefixed `quick-party-` deliberately. Modules are mounted into one
| page now, and `#party-drawer` is already taken: on the counterparty screens it
| is the record *viewer*. Two nodes under one id, in a shell that keeps module
| roots alive, is a bug that only appears once somebody opens both.
--}}
<div id="quick-party-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="quick-party-title">
    <div class="drawer-panel">
        <form id="quick-party-form" novalidate>
            <input type="hidden" name="id">

            <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"
                    data-form-chrome="modal">
                <div>
                    <h2 class="text-base font-bold text-foreground" id="quick-party-title">New customer</h2>
                    <p class="mt-0.5 text-[0.8125rem] text-muted-foreground" id="quick-party-subtitle"></p>
                </div>

                <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                    <x-icon name="x" :size="18" />
                </button>
            </header>

            <div data-form-body>
                <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem]
                          text-rose-700" data-form-banner role="alert"></p>

                <div>
                    <label for="quick-party-name" class="field-label" id="quick-party-name-label">Name</label>
                    <input id="quick-party-name" name="name" type="text" class="field-input" required
                           autocomplete="off">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Names are unique, because two records with one name split a single balance in two.
                    </p>
                    <p class="field-error hidden" data-error-for="name"></p>
                </div>

                <div data-half>
                    <label for="quick-party-phone" class="field-label">Phone</label>
                    <input id="quick-party-phone" name="phone" type="tel" class="field-input"
                           inputmode="tel" maxlength="20" autocomplete="off" placeholder="98765 43210">
                    <p class="field-error hidden" data-error-for="phone"></p>
                </div>

                <div data-half>
                    <label for="quick-party-gstin" class="field-label">
                        GSTIN <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="quick-party-gstin" name="gstin" type="text" maxlength="15"
                           class="field-input font-mono uppercase" autocomplete="off"
                           placeholder="27AAAAA0000A1Z5">
                    {{-- Said plainly: it decides whether the bill carries IGST or
                         CGST+SGST, and that is not something to discover after
                         posting. --}}
                    <p class="mt-1.5 text-xs text-muted-foreground" id="quick-party-state-hint">
                        The first two digits set the state, which decides CGST/SGST or IGST.
                    </p>
                    <p class="field-error hidden" data-error-for="gstin"></p>
                </div>

                <div data-quick-party-full data-half class="hidden">
                    <label for="quick-party-email" class="field-label">
                        Email <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="quick-party-email" name="email" type="email" class="field-input" autocomplete="off">
                    <p class="field-error hidden" data-error-for="email"></p>
                </div>

                <div>
                    <label for="quick-party-address" class="field-label">
                        Address <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <textarea id="quick-party-address" name="address" rows="3" maxlength="500"
                              class="field-input !h-auto py-2"
                              placeholder="Shop / Plot no., Area, City – Pincode"></textarea>
                    <p class="field-error hidden" data-error-for="address"></p>
                </div>

                <div data-quick-party-full class="hidden">
                    <label for="quick-party-notes" class="field-label">
                        Notes <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <textarea id="quick-party-notes" name="notes" rows="2" maxlength="500"
                              class="field-input !h-auto py-2"
                              placeholder="Payment terms, site contact, anything worth remembering."></textarea>
                    <p class="field-error hidden" data-error-for="notes"></p>
                </div>

                {{-- Deliberately absent: an opening balance, which the design's
                     form offers here. It is a posting, not a contact detail, and
                     it belongs to the go-live screen that balances every such
                     figure against the others. Taking it on this form would let
                     somebody put money into the books through a dialog whose
                     other seven fields are a phone number and an address. --}}
                <p data-quick-party-full
                   class="hidden rounded-[10px] border border-border bg-background px-3.5 py-2.5 text-xs
                          text-muted-foreground">
                    Opening balances are declared together on
                    <a href="{{ route('opening.index') }}" class="font-medium text-primary hover:underline">Opening
                    balances</a>, where they are balanced against everything else the workshop started with.
                </p>
            </div>

            <footer class="flex items-center gap-2 border-t border-border px-5 py-4"
                    data-form-chrome="modal">
                <button type="button" class="btn btn-secondary flex-1" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary flex-1" id="quick-party-submit"
                        data-quick-party-submit>Save</button>
            </footer>

            {{-- The level-1 footer, shown when the form is mounted inline on a
                 counterparty module rather than in this drawer. "Clear" rather
                 than "Cancel": there is nothing to cancel out of — the form is
                 where the module lives, and leaving it is what the workspace's
                 switch control above it is for (§2A.3). --}}
            <div class="form-foot" data-form-chrome="inline">
                <button type="submit" class="btn btn-primary" data-quick-party-submit>Save</button>
                <button type="button" class="btn btn-ghost" data-quick-party-clear>Clear</button>
            </div>
        </form>
    </div>
</div>
