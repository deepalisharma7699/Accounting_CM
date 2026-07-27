<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-full place-items-center px-4 py-10">

    <div class="w-full max-w-[400px]">

        {{-- Brand mark: same tile treatment as the sidebar logo --}}
        <div class="mb-8 flex flex-col items-center text-center">
            <span class="grid size-12 place-items-center rounded-[14px] bg-primary text-primary-foreground
                         shadow-primary-glow">
                <x-icon name="zap" :size="24" />
            </span>

            <h1 class="mt-5 text-2xl font-bold tracking-tight text-foreground">Welcome back</h1>
            <p class="mt-1.5 text-[0.9375rem] text-muted-foreground">
                Sign in to your AI Accounting Back Office
            </p>
        </div>

        <div class="surface p-6 sm:p-7">
            <form id="login-form" novalidate>

                {{-- Populated from the API's error envelope: error.error.message --}}
                <div id="login-error"
                     class="mb-5 hidden items-start gap-2.5 rounded-[10px] border border-rose-200 bg-rose-50
                            px-3.5 py-3 text-[0.8125rem] text-rose-700"
                     role="alert" aria-live="polite">
                    <x-icon name="alert-triangle" :size="16" class="mt-px shrink-0" />
                    <span data-error-message></span>
                </div>

                {{-- Email --}}
                <div class="mb-4">
                    <label for="email" class="mb-1.5 block text-[0.8125rem] font-semibold text-secondary-foreground">
                        Email address
                    </label>

                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            <x-icon name="mail" :size="17" />
                        </span>

                        <input id="email" name="email" type="email" autocomplete="email" required
                               placeholder="you@company.com"
                               class="h-11 w-full rounded-[10px] border border-border bg-card pl-10 pr-3.5 text-sm
                                      text-foreground placeholder:text-muted-foreground focus:border-primary
                                      focus:outline-none focus:ring-2 focus:ring-ring/60">
                    </div>

                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="email"></p>
                </div>

                {{-- Password --}}
                <div class="mb-5">
                    <div class="mb-1.5 flex items-baseline justify-between">
                        <label for="password" class="text-[0.8125rem] font-semibold text-secondary-foreground">
                            Password
                        </label>
                        <a href="#" class="text-[0.8125rem] font-medium text-primary hover:underline">
                            Forgot password?
                        </a>
                    </div>

                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                            <x-icon name="lock" :size="17" />
                        </span>

                        <input id="password" name="password" type="password" autocomplete="current-password" required
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

                    <p class="mt-1.5 hidden text-xs text-rose-600" data-field-error="password"></p>
                </div>

                <button type="submit" data-submit
                        class="flex h-11 w-full items-center justify-center gap-2 rounded-[10px] bg-primary
                               text-sm font-semibold text-primary-foreground shadow-primary-glow transition
                               hover:brightness-110 focus:outline-none focus-visible:ring-2
                               focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60">
                    <x-icon name="loader" :size="16" class="hidden animate-spin" data-spinner />
                    <span data-submit-label>Sign in</span>
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-[0.8125rem] text-muted-foreground">
            Protected by rate limiting and account lockout.
        </p>
    </div>

</body>
</html>
