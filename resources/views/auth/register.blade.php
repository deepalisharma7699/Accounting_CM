<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create your workshop · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-full place-items-center px-4 py-10">

    <div class="w-full max-w-[440px]">

        <div class="mb-8 flex flex-col items-center text-center">
            <span class="grid size-12 place-items-center rounded-[14px] bg-primary text-primary-foreground
                         shadow-primary-glow">
                <x-icon name="zap" :size="24" />
            </span>

            <h1 class="mt-5 text-2xl font-bold tracking-tight text-foreground">Create your workshop</h1>
            <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
                Set up your books in under a minute
            </p>
        </div>

        <div class="surface p-6 sm:p-7">
            <form id="register-form" novalidate>

                <div id="register-error"
                     class="mb-5 hidden items-start gap-2.5 rounded-[10px] border border-rose-200 bg-rose-50
                            px-3.5 py-3 text-[0.8125rem] text-rose-700"
                     role="alert" aria-live="polite">
                    <x-icon name="alert-triangle" :size="16" class="mt-px shrink-0" />
                    <span data-error-message></span>
                </div>

                {{-- The workshop --}}
                <p class="mb-3 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">
                    Your workshop
                </p>

                <div class="mb-4">
                    <label for="workshop_name" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        Workshop name
                    </label>
                    <input id="workshop_name" name="workshop_name" type="text" required autocomplete="organization"
                           placeholder="Sharma Electricals"
                           class="h-11 w-full rounded-[10px] border border-border bg-card px-3.5 text-sm
                                  text-foreground placeholder:text-muted-foreground focus:border-primary
                                  focus:outline-none focus:ring-2 focus:ring-ring/60">
                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="workshop_name"></p>
                </div>

                <div class="mb-6">
                    <label for="gstin" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        GSTIN <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="gstin" name="gstin" type="text" maxlength="15" autocomplete="off"
                           placeholder="27AAPFU0939F1ZV"
                           class="h-11 w-full rounded-[10px] border border-border bg-card px-3.5 font-mono text-sm
                                  uppercase text-foreground placeholder:font-sans placeholder:text-muted-foreground
                                  focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/60">
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        You can add this later. It sets your state for GST.
                    </p>
                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="gstin"></p>
                </div>

                {{-- The owner --}}
                <p class="mb-3 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">
                    Your account
                </p>

                <div class="mb-4">
                    <label for="name" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        Your name
                    </label>
                    <input id="name" name="name" type="text" required autocomplete="name" placeholder="Ravi Sharma"
                           class="h-11 w-full rounded-[10px] border border-border bg-card px-3.5 text-sm
                                  text-foreground placeholder:text-muted-foreground focus:border-primary
                                  focus:outline-none focus:ring-2 focus:ring-ring/60">
                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="name"></p>
                </div>

                <div class="mb-4">
                    <label for="email" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        Email address
                    </label>

                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            <x-icon name="mail" :size="17" />
                        </span>
                        <input id="email" name="email" type="email" required autocomplete="email"
                               placeholder="you@company.com"
                               class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-3.5 text-sm
                                      text-foreground placeholder:text-muted-foreground focus:border-primary
                                      focus:outline-none focus:ring-2 focus:ring-ring/60">
                    </div>
                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="email"></p>
                </div>

                <div class="mb-5">
                    <label for="password" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        Password
                    </label>

                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            <x-icon name="lock" :size="17" />
                        </span>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               placeholder="••••••••••••"
                               class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-11 text-sm
                                      text-foreground placeholder:text-muted-foreground focus:border-primary
                                      focus:outline-none focus:ring-2 focus:ring-ring/60">

                        <button type="button" data-toggle-password aria-label="Show password"
                                class="absolute right-2 top-1/2 grid size-8 -translate-y-1/2 place-items-center
                                       rounded-md text-muted-foreground transition hover:bg-muted
                                       hover:text-secondary-foreground">
                            <x-icon name="eye" :size="17" data-icon-show />
                            <x-icon name="eye-off" :size="17" class="hidden" data-icon-hide />
                        </button>
                    </div>
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        At least 12 characters, with upper and lower case, a number and a symbol.
                    </p>
                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="password"></p>
                </div>

                <div class="mb-5">
                    <label for="password_confirmation" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        Confirm password
                    </label>

                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            <x-icon name="lock" :size="17" />
                        </span>
                        <input id="password_confirmation" name="password_confirmation" type="password" required
                               autocomplete="new-password" placeholder="••••••••••••"
                               class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-3.5 text-sm
                                      text-foreground placeholder:text-muted-foreground focus:border-primary
                                      focus:outline-none focus:ring-2 focus:ring-ring/60">
                    </div>
                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="password_confirmation"></p>
                </div>

                <button type="submit" data-submit
                        class="flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-primary
                               text-sm font-semibold text-primary-foreground shadow-primary-glow transition
                               hover:brightness-110 focus:outline-none focus-visible:ring-2
                               focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60">
                    <x-icon name="loader" :size="16" class="hidden animate-spin" data-spinner />
                    <span data-submit-label>Create workshop</span>
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-[0.8125rem] text-muted-foreground">
            Already have an account?
            <a href="{{ route('login') }}" class="font-medium text-primary hover:underline">Sign in</a>
        </p>
    </div>

</body>
</html>
