
{{-- Shown after sign-up (/workspace?welcome=1). The two settings below decide
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

<header class="mb-6">
    <h2 class="text-2xl font-bold tracking-tight text-foreground">Workshop settings</h2>
    <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
        Your workshop's identity and the settings every report is built on.
    </p>
</header>

<form id="workspace-form" novalidate class="max-w-3xl space-y-4">

    <p class="hidden rounded-[10px] border border-rose-200 bg-rose-50 px-3.5 py-3 text-[0.8125rem] text-rose-700"
       data-form-banner role="alert"></p>

    {{-- Identity --}}
    <section class="surface p-5 sm:p-6">
        <h3 class="text-sm font-bold text-foreground">Identity</h3>
        <p class="mt-1 text-[0.8125rem] text-muted-foreground">
            How the workshop appears on documents.
        </p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="ws-name" class="field-label">Workshop name</label>
                <input id="ws-name" name="name" type="text" class="field-input" required autocomplete="organization">
                <p class="field-error hidden" data-error-for="name"></p>
            </div>

            <div>
                <span class="field-label">Handle</span>
                <div class="flex h-[2.625rem] items-center rounded-[10px] border border-border bg-muted px-3
                            font-mono text-[0.8125rem] text-muted-foreground">
                    <span data-ws-slug>—</span>
                </div>
                <p class="mt-1.5 text-xs text-muted-foreground">Fixed. Renaming leaves it unchanged.</p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="ws-gstin" class="field-label">GSTIN</label>
                <input id="ws-gstin" name="gstin" type="text" maxlength="15"
                       class="field-input font-mono uppercase" autocomplete="off" placeholder="27AAPFU0939F1ZV">
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Sets your state, which decides CGST/SGST versus IGST on every bill.
                </p>
                <p class="field-error hidden" data-error-for="gstin"></p>
            </div>

            <div>
                <label for="ws-state-code" class="field-label">State code</label>
                <input id="ws-state-code" name="state_code" type="text" maxlength="2" inputmode="numeric"
                       class="field-input font-mono" autocomplete="off" placeholder="27">
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
    <section class="surface p-5 sm:p-6">
        <h3 class="text-sm font-bold text-foreground">Books</h3>
        <p class="mt-1 text-[0.8125rem] text-muted-foreground">
            These decide which period a report covers and how far back entries may be dated.
        </p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
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
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="ws-books-start" class="field-label">Books start date</label>
                <input id="ws-books-start" name="books_start_date" type="date" class="field-input">
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Your go-live day. Nothing may be dated before it — that period belongs to whatever you used
                    previously, and its closing position comes in as opening balances.
                </p>
                <p class="field-error hidden" data-error-for="books_start_date"></p>
            </div>

            <div>
                <span class="field-label">Currency</span>
                <div class="flex h-[2.625rem] items-center rounded-[10px] border border-border bg-muted px-3
                            text-[0.8125rem] text-muted-foreground">
                    <span data-ws-currency>INR</span>
                </div>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Fixed — GST, HSN/SAC and the tax engine are India-specific.
                </p>
            </div>
        </div>
    </section>

    <div class="flex items-center justify-end gap-3">
        <span class="text-[0.8125rem] text-muted-foreground" data-ws-status></span>
        <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
</form>

