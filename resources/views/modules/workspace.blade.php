{{--
    Workshop settings — the workshop's own record.

    **One record, so one surface.** There is nothing to create here, so the
    module declares only `data-ws-list` and mounts with `canCreate: false` —
    §2A.10's judgement applied to a module with a single record rather than to a
    read-mostly one. The workspace lands straight on the form and paints no
    switch control, and the frame is still the shared one: the heading, the
    Escape handling and the URL sync are not this module's to reinvent. There is
    deliberately no single-surface mode in `workspace.js` for this.

    ## The rules section is not decoration

    `payment_due_days`, `allow_negative_stock` and `round_off_invoices` are
    accepted by UpdateWorkspaceRequest and read by Tenant, StockLedgerService,
    RoundOff and the Insights ageing panel — and until this section existed, no
    screen offered any of them. Each changes what the application refuses or how
    it reports, so each says what it does beside the control rather than in a
    document nobody opens.

    ## What is shown and never edited

    The handle and the currency are read-only text rather than disabled inputs:
    the slug may already be in URLs, logs and support tickets, and the tax engine
    is India-specific, so anything but INR would format correctly and compute
    wrongly. TenantService::updateOwnWorkspace() strips both again, along with
    `status`, so a caller that bypasses this form cannot slip them through.
--}}
<div class="mx-auto max-w-3xl">

    {{-- Level 1, and the only surface this module has. --}}
    <div data-ws-list>

    {{-- Shown after sign-up (#workspace?welcome=1). The settings below decide
         how every future report reads, so they are worth confirming before
         anything is posted rather than after. --}}
    <div id="welcome-banner" class="surface mb-6 hidden items-start gap-3 border-primary/30 bg-accent/40 p-4">
        <span class="grid size-9 shrink-0 place-items-center rounded-[10px] bg-primary text-primary-foreground">
            <x-icon name="check-circle" :size="18" />
        </span>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-foreground">Your workshop is ready</p>
            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                A chart of accounts has already been created for you. Confirm your GSTIN and financial year below —
                both decide how your reports and tax figures come out, and they are easiest to get right now.
            </p>
        </div>
    </div>

    <form id="workspace-form" novalidate class="space-y-4">

        {{-- Identity --}}
        <section class="surface form-card">
            <h3 class="text-sm font-bold text-foreground">Identity</h3>
            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                How the workshop appears on documents.
            </p>

            <div class="mt-4 form-grid">
                <div>
                    <label for="ws-name" class="field-label">Workshop name</label>
                    <input id="ws-name" name="name" type="text" class="field-input" required autocomplete="organization">
                    <p class="field-error hidden" data-error-for="name"></p>
                </div>

                <div>
                    <span class="field-label">Handle</span>
                    <div class="flex h-[var(--control-h)] items-center rounded-[10px] border border-border bg-muted px-3
                                font-mono text-[0.8125rem] text-muted-foreground">
                        <span data-ws-slug>—</span>
                    </div>
                    <p class="mt-1.5 text-xs text-muted-foreground">Fixed. Renaming leaves it unchanged.</p>
                </div>
            
                <div>
                    <label for="ws-gstin" class="field-label">GSTIN</label>
                    <input id="ws-gstin" name="gstin" type="text" maxlength="15"
                           class="field-input field-code font-mono uppercase" autocomplete="off" placeholder="27AAPFU0939F1ZV">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Sets your state, which decides CGST/SGST versus IGST on every bill.
                    </p>
                    <p class="field-error hidden" data-error-for="gstin"></p>
                </div>

                <div>
                    <label for="ws-state-code" class="field-label">State code</label>
                    <input id="ws-state-code" name="state_code" type="text" maxlength="2" inputmode="numeric"
                           class="field-input field-num font-mono" autocomplete="off" placeholder="27">
                    <p class="mt-1.5 text-xs text-muted-foreground">Taken from the GSTIN when one is set.</p>
                    <p class="field-error hidden" data-error-for="state_code"></p>
                </div>
            </div>

            <div class="mt-4">
                <label for="ws-address" class="field-label">Address</label>
                <textarea id="ws-address" name="address" rows="2" class="field-input !h-auto py-2"></textarea>
                <p class="field-error hidden" data-error-for="address"></p>
            </div>
        </section>

        {{-- Books --}}
        <section class="surface form-card">
            <h3 class="text-sm font-bold text-foreground">Books</h3>
            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                These decide which period a report covers and how far back entries may be dated.
            </p>

            <div class="mt-4 form-grid">
                <div>
                    <label for="ws-fy" class="field-label">Financial year starts in</label>
                    <select id="ws-fy" name="financial_year_start_month" class="field-input">
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}">{{ \Carbon\CarbonImmutable::create(null, $month, 1)->format('F') }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-muted-foreground" data-ws-fy-range>—</p>
                    <p class="field-error hidden" data-error-for="financial_year_start_month"></p>
                </div>

                <div>
                    <label for="ws-timezone" class="field-label">Timezone</label>
                    <select id="ws-timezone" name="timezone" class="field-input">
                        @foreach (['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'UTC'] as $zone)
                            <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-muted-foreground">Used for transaction dates and the day book.</p>
                    <p class="field-error hidden" data-error-for="timezone"></p>
                </div>
            
                <div>
                    <label for="ws-books-start" class="field-label">Books start date</label>
                    <input id="ws-books-start" name="books_start_date" type="date" class="field-input field-date">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Your go-live day. Nothing may be dated before it — that period belongs to whatever you used
                        previously, and its closing position comes in as opening balances.
                    </p>
                    <p class="field-error hidden" data-error-for="books_start_date"></p>
                </div>

                <div>
                    <span class="field-label">Currency</span>
                    <div class="flex h-[var(--control-h)] items-center rounded-[10px] border border-border bg-muted px-3
                                text-[0.8125rem] text-muted-foreground">
                        <span data-ws-currency>INR</span>
                    </div>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Fixed — GST, HSN/SAC and the tax engine are India-specific.
                    </p>
                </div>
            </div>
        </section>

        {{-- Rules.

             The three settings the API has always accepted and no screen had
             ever offered. Each changes what the application refuses or how it
             reports; none of them restates a figure that is already posted. --}}
        <section class="surface form-card">
            <h3 class="text-sm font-bold text-foreground">Rules</h3>
            <p class="mt-1 text-[0.8125rem] text-muted-foreground">
                What the application refuses, and what it reports. Changing one of these never restates
                anything already in the books.
            </p>

            <div class="mt-4">
                <label for="ws-due-days" class="field-label">
                    Payment terms <span class="font-normal text-muted-foreground">(optional)</span>
                </label>
                <div class="relative field-short">
                    <input id="ws-due-days" name="payment_due_days" type="text" inputmode="numeric"
                           class="field-input pr-14 font-mono" autocomplete="off" placeholder="30">
                    <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                        days
                    </span>
                </div>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    How long a bill may go unsettled before it is reported overdue. Leave it empty if you settle at
                    the counter: the money-owed ageing then measures from the invoice date, and says on the panel
                    that it has no agreed terms to measure against.
                </p>
                <p class="field-error hidden" data-error-for="payment_due_days"></p>
            </div>

            <div class="mt-4 border-t border-border pt-4">
                <div class="flex items-start gap-2.5">
                    <input id="ws-negative-stock" name="allow_negative_stock" type="checkbox"
                           class="mt-0.5 size-4 rounded border-border">
                    <label for="ws-negative-stock" class="text-sm text-secondary-foreground">
                        Allow a bill to take stock the shelf does not hold
                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            Off, and an issue that would take a variant below zero is refused. On is for a workshop
                            that routinely bills before the purchase has been entered: the issue posts and the
                            variant is reported short until a count puts it right.
                        </span>
                    </label>
                </div>
                <p class="field-error hidden" data-error-for="allow_negative_stock"></p>
            </div>

            <div class="mt-4 border-t border-border pt-4">
                <div class="flex items-start gap-2.5">
                    <input id="ws-round-off" name="round_off_invoices" type="checkbox"
                           class="mt-0.5 size-4 rounded border-border">
                    <label for="ws-round-off" class="text-sm text-secondary-foreground">
                        Round a bill to the nearest rupee
                        <span class="mt-0.5 block text-xs text-muted-foreground">
                            This changes the total a customer pays. The paise are booked to Round Off, so the books
                            still balance to the last paisa.
                        </span>
                    </label>
                </div>
                <p class="field-error hidden" data-error-for="round_off_invoices"></p>
            </div>
        </section>

        {{-- Absent rather than blanked for a reader: a disabled Save asks
             somebody to work out for themselves why it will not press. The
             fields are set read-only from the page module for the same caller. --}}
        <div class="flex items-center justify-end gap-3" data-requires-permission="UPDATE:WORKSPACE">
            <button type="submit" class="btn btn-primary">Save settings</button>
        </div>

        {{-- What a reader is told instead. --}}
        <p class="hidden text-right text-[0.8125rem] text-muted-foreground" data-ws-readonly>
            You have read-only access to these settings.
        </p>
    </form>

    </div>{{-- /data-ws-list --}}
</div>
