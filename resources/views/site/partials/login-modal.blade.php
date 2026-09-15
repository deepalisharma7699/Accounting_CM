{{--
    Sign in.
    ======================================================================

    The form the standalone /login page used to carry, as a modal on the public
    site. Every id and data- hook here is exactly what initLogin() in
    resources/js/app.js binds to, so the credential flow behind it — field
    errors, the 422 envelope, the busy state and the redirect to /dashboard —
    is the one that was already there and is untouched by this design.

    Only the wording is translated. Do not rename an id to match the new `s-`
    prefix used elsewhere on this site: these names are an interface with the
    application's JavaScript, not styling.
--}}
@php use App\Support\Site; @endphp

<div id="login-modal" data-modal class="modal-backdrop hidden">
    <div class="modal-panel s-modal-in max-w-[420px] p-6 sm:p-7">

        <div class="flex items-start justify-between gap-4">
            <div>
                <span class="grid size-11 place-items-center rounded-[13px] bg-primary text-primary-foreground
                             shadow-primary-glow">
                    <x-icon name="zap" :size="22" />
                </span>
                <h2 class="mt-4 text-xl font-bold tracking-tight text-foreground">
                    {{ Site::text('login.title') }}
                </h2>
                <p class="mt-1 text-[0.875rem] text-muted-foreground">
                    {{ Site::text('login.subtitle') }}
                </p>
            </div>

            <button type="button" data-modal-close aria-label="{{ Site::text('login.close') }}"
                    class="btn btn-ghost btn-icon -mr-1 -mt-1">
                <x-icon name="x" :size="18" />
            </button>
        </div>

        {{--
            The passkey, first.
            ------------------------------------------------------------------

            Hidden in the markup and revealed by resources/js/app.js only once
            the browser has said it can actually perform the ceremony. That
            order matters: a button offering a fingerprint on a machine with no
            authenticator is a dead end on the one screen nobody can get past,
            so the default state is "not offered" and support has to be proven.

            It sits above the password fields rather than replacing them.
            Passkeys are the way in for somebody who has enrolled a device, and
            the password is the way in on a new phone, a borrowed machine, and
            the very first sign-in — and there is no way to know which of those
            this is, so both are on one screen with no disclosure to hunt for.
        --}}
        <div id="passkey-signin" class="mt-6 hidden">
            <button type="button" data-passkey-signin
                    class="flex h-11 w-full items-center justify-center gap-2 rounded-[10px] border
                           border-primary/40 bg-primary/5 text-sm font-semibold text-primary transition
                           hover:bg-primary/10 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60">
                <x-icon name="loader" :size="16" class="hidden animate-spin" data-passkey-spinner />
                <x-icon name="fingerprint" :size="17" data-passkey-icon />
                <span data-passkey-label data-busy="{{ Site::text('login.passkey_busy') }}">
                    {{ Site::text('login.passkey') }}
                </span>
            </button>

            <p class="mt-2 text-center text-[0.75rem] text-muted-foreground">
                {{ Site::text('login.passkey_hint') }}
            </p>

            {{-- Its own error line. A passkey that fails must not paint the
                 password form's banner, which would read as "your password is
                 wrong" to somebody who never typed one. --}}
            <p class="mt-2 hidden text-center text-[0.8125rem] text-rose-600"
               data-passkey-error role="alert" aria-live="polite"></p>

            <div class="my-5 flex items-center gap-3">
                <span class="h-px flex-1 bg-border"></span>
                <span class="text-[0.75rem] text-muted-foreground">{{ Site::text('login.or') }}</span>
                <span class="h-px flex-1 bg-border"></span>
            </div>
        </div>

        <form id="login-form" class="mt-6" novalidate>

            <div class="mb-4">
                <label for="email" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                    {{ Site::text('login.email') }}
                </label>

                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <x-icon name="mail" :size="17" />
                    </span>

                    {{-- `webauthn` in the autocomplete is what lets the browser
                         offer a passkey from this field's own autofill list —
                         see initPasskeySignIn() in resources/js/app.js, which
                         only arms it where the browser supports it. --}}
                    <input id="email" name="email" type="email" autocomplete="username webauthn" required
                           placeholder="you@company.com"
                           class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-3.5 text-sm
                                  text-foreground placeholder:text-muted-foreground focus:border-primary
                                  focus:outline-none focus:ring-2 focus:ring-ring/60">
                </div>

                <p class="mt-1.5 hidden text-xs text-rose-600" data-error-for="email"></p>
            </div>

            <div class="mb-5">
                <label for="password" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                    {{ Site::text('login.password') }}
                </label>

                <div class="relative">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <x-icon name="lock" :size="17" />
                    </span>

                    <input id="password" name="password" type="password" autocomplete="current-password" required
                           placeholder="••••••••••••"
                           class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-11 text-sm
                                  text-foreground placeholder:text-muted-foreground focus:border-primary
                                  focus:outline-none focus:ring-2 focus:ring-ring/60">

                    <button type="button" data-toggle-password
                            aria-label="{{ Site::text('login.show_password') }}"
                            class="absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center
                                   rounded-md text-muted-foreground transition hover:bg-muted
                                   hover:text-secondary-foreground">
                        <x-icon name="eye" :size="17" data-icon-show />
                        <x-icon name="eye-off" :size="17" class="hidden" data-icon-hide />
                    </button>
                </div>

                <p class="mt-1.5 hidden text-xs text-rose-600" data-error-for="password"></p>
            </div>

            <button type="submit" data-submit
                    class="flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-primary
                           text-sm font-semibold text-primary-foreground shadow-primary-glow transition
                           hover:brightness-110 focus:outline-none focus-visible:ring-2
                           focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60">
                <x-icon name="loader" :size="16" class="hidden animate-spin" data-spinner />
                <span data-submit-label>{{ Site::text('login.submit') }}</span>
            </button>
        </form>

        {{-- Off by default: onboarding is sales-led, so the modal signs people
             in and offers nothing else. Gated on the config flag rather than
             deleted, because `TENANCY_ALLOW_PUBLIC_SIGNUP` moves the link, the
             /register route and the API endpoint together — deleting the markup
             instead would hide the link while leaving /register reachable to
             anybody who types it. --}}
        @if (config('tenancy.allow_public_signup', true))
            <p class="mt-5 text-center text-[0.8125rem] text-muted-foreground">
                {{ Site::text('login.signup_prompt') }}
                <a href="{{ route('register') }}" class="font-medium text-primary hover:underline">
                    {{ Site::text('login.signup_link') }}
                </a>
            </p>
        @endif

        <p class="mt-2.5 text-center text-[0.75rem] text-muted-foreground">
            {{ Site::text('login.staff_only') }}
        </p>
    </div>
</div>
