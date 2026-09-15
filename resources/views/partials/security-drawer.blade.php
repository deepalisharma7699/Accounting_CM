{{--
    Sign-in security — the devices that can open this account.
    ======================================================================

    A level-2 drawer rather than a level-3 modal, and that is a rule rather than
    a preference (CLAUDE.md §2.2): removing a device asks for a confirmation,
    and a confirmation is itself level 3. A modal here would put a modal over a
    modal, which is the shape §2.2 exists to prevent.

    It hangs off the topbar's account menu rather than being a module, because
    it is not one: there is no card for it, no list to search and nothing
    tenant-scoped in it. It is the same kind of thing as "sign out" — something
    you do to your own session, from the chrome that names whose session it is.

    Everything in here is driven by components/passkey-manager.js. The markup is
    the empty frame; no row is rendered server-side, because the list belongs to
    whoever is signed in and this shell is served before that is known.
--}}

<div id="security-drawer" class="drawer-backdrop hidden" data-modal role="dialog" aria-modal="true"
     aria-labelledby="security-drawer-title">
    <div class="drawer-panel max-w-[460px]">

        <div class="flex items-start justify-between gap-3 border-b border-muted px-6 py-5">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-[13px] bg-primary/10 text-primary">
                    <x-icon name="fingerprint" :size="22" />
                </span>
                <div class="min-w-0">
                    <h3 id="security-drawer-title" class="truncate text-base font-bold leading-tight text-foreground">
                        Sign-in security
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        The devices that can open your account
                    </p>
                </div>
            </div>

            <button type="button" class="btn btn-ghost btn-icon" data-modal-close aria-label="Close">
                <x-icon name="x" :size="16" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">

            {{--
                Add this device.

                Hidden until the browser confirms it can perform a ceremony —
                the same rule the sign-in dialog follows, and for a stronger
                reason here: this screen is reached from inside a working
                session, so a button that cannot work is pure noise on a screen
                somebody opened on purpose.
            --}}
            <div data-passkey-add class="hidden">
                {{--
                    The name, before the ceremony rather than after it.

                    Asked here rather than in a browser prompt for two reasons:
                    a prompt is a different interaction pattern from everything
                    else in this application (§7.4), and on iOS it must be
                    dismissed before the passkey sheet can open, which puts two
                    system dialogs in a row on the smallest screen. Prefilled
                    with a guess so this is a correction, not a blank.
                --}}
                <label for="passkey-label" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                    What is this device called?
                </label>

                <input id="passkey-label" data-passkey-label-input type="text" maxlength="80"
                       placeholder="Ramesh's phone"
                       class="mb-3 h-11 w-full rounded-[10px] border border-border bg-card px-3.5 text-sm
                              text-foreground placeholder:text-muted-foreground focus:border-primary
                              focus:outline-none focus:ring-2 focus:ring-ring/60">

                <button type="button" data-passkey-enrol
                        class="flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-primary
                               text-sm font-semibold text-primary-foreground shadow-primary-glow transition
                               hover:brightness-110 focus:outline-none focus-visible:ring-2
                               focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60">
                    <x-icon name="loader" :size="16" class="hidden animate-spin" data-passkey-spinner />
                    <x-icon name="plus" :size="17" data-passkey-icon />
                    <span data-passkey-label data-busy="Waiting for your device…">Add this device</span>
                </button>

                <p class="mt-2 text-[0.75rem] leading-relaxed text-muted-foreground">
                    You will be asked for your fingerprint, face or screen lock. Nothing leaves this
                    device — the workshop never sees it, and neither do we.
                </p>
            </div>

            {{-- Where the browser cannot do this at all. Stated rather than
                 left blank: an empty panel reads as something still loading. --}}
            <p data-passkey-unsupported
               class="hidden rounded-[10px] border border-border bg-muted/40 px-3.5 py-3 text-[0.8125rem]
                      text-muted-foreground">
                This browser cannot store a passkey. Try Chrome, Safari or Edge on a device with a
                screen lock, and keep using your password here.
            </p>

            <p class="mt-2.5 hidden text-[0.8125rem] text-rose-600"
               data-passkey-error role="alert" aria-live="polite"></p>

            <div class="my-5 h-px bg-border"></div>

            <h4 class="section-label">Your devices</h4>

            {{-- One list, four states — loading, empty, rows, failed — because
                 "no devices yet" and "we could not fetch your devices" must
                 never look the same on a security screen (§3.4). --}}
            <div class="mt-3" data-passkey-list aria-live="polite"></div>

            <p class="mt-5 text-[0.75rem] leading-relaxed text-muted-foreground">
                Signing in with a device keeps you signed in for months. Your password still works,
                but only keeps you signed in for a week — so if you lose a device, remove it here and
                it can no longer open your account.
            </p>
        </div>
    </div>
</div>
