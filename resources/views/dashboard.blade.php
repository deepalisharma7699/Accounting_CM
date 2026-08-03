@extends('layouts.app')

@section('title', 'Dashboard')

@php
    /*
    | Tone -> Tailwind class map for the coloured icon tiles and badges. Kept in
    | one place so a new tone is a single entry rather than a search-and-replace.
    */
    $tones = [
        'blue'   => ['tile' => 'bg-blue-50 text-blue-600',       'badge' => 'bg-blue-50 text-blue-700'],
        'green'  => ['tile' => 'bg-emerald-50 text-emerald-600', 'badge' => 'bg-emerald-50 text-emerald-700'],
        'purple' => ['tile' => 'bg-violet-50 text-violet-600',   'badge' => 'bg-violet-50 text-violet-700'],
        'amber'  => ['tile' => 'bg-amber-50 text-amber-600',     'badge' => 'bg-amber-50 text-amber-700'],
        'rose'   => ['tile' => 'bg-rose-50 text-rose-600',       'badge' => 'bg-rose-50 text-rose-700'],
        'slate'  => ['tile' => 'bg-muted text-secondary-foreground', 'badge' => 'bg-muted text-secondary-foreground'],
    ];
@endphp

@section('content')

{{-- Greeting. Name is filled in by app.js from the authenticated user. --}}
<header class="mb-8">
    <h2 class="flex items-center gap-3 text-3xl font-bold tracking-tight text-foreground">
        <span>{{ $greeting }},</span>
        <span data-user-firstname class="inline-block min-w-24 rounded bg-muted text-transparent">&nbsp;</span>
        <span class="text-2xl" aria-hidden="true">👋</span>
    </h2>
    <p class="mt-2 text-[0.9375rem] text-muted-foreground">
        {{-- Painted client-side from /auth/me, like the sidebar: the shell is
             public, and which workshop a session belongs to is session data. --}}
        Welcome back to <span data-workspace-name>your workspace</span>. Here's what needs your attention today.
    </p>
</header>

<div class="grid grid-cols-1 items-start gap-8 xl:grid-cols-[minmax(0,1fr)_var(--spacing-rail)]">

    {{-- ============================ Main column ============================ --}}
    <div class="space-y-8">

        {{-- Quick actions --}}
        <section aria-labelledby="quick-actions">
            <h3 id="quick-actions" class="section-label mb-3">Quick Actions</h3>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach ($quickActions as $action)
                    <a href="#" class="surface group flex flex-col p-4 transition hover:shadow-primary
                                       focus:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                        <span class="grid size-10 place-items-center rounded-[10px] {{ $tones[$action['tone']]['tile'] }}">
                            <x-icon :name="$action['icon']" :size="20" />
                        </span>

                        <span class="mt-4 text-[0.9375rem] font-semibold leading-snug text-foreground">
                            {{ $action['title'] }}
                        </span>
                        <span class="mt-1.5 text-[0.8125rem] leading-relaxed text-muted-foreground">
                            {{ $action['description'] }}
                        </span>

                        {{-- mt-auto keeps the arrows on a common baseline even
                             when the titles wrap to different heights. --}}
                        <span class="mt-auto flex justify-end pt-4 text-border transition
                                     group-hover:text-primary">
                            <x-icon name="arrow-up-right" :size="16" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- Needs your attention --}}
        <section aria-labelledby="attention">
            <div class="mb-3 flex items-center justify-between">
                <h3 id="attention" class="section-label">Needs Your Attention</h3>
                <span class="text-[0.8125rem] text-muted-foreground">
                    {{ $attentionPending }} pending
                </span>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($attention as $item)
                    <article class="surface flex items-center gap-3 p-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-[10px] {{ $tones[$item['tone']]['tile'] }}">
                            <x-icon :name="$item['icon']" :size="20" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-[0.9375rem] leading-snug text-foreground">
                                <span class="font-bold">{{ $item['count'] }}</span>
                                <span class="font-medium">{{ $item['label'] }}</span>
                            </p>
                            <p class="mt-0.5 truncate text-[0.8125rem] text-muted-foreground">
                                {{ $item['description'] }}
                            </p>
                        </div>

                        <a href="#" class="shrink-0 rounded-lg bg-accent px-3 py-1.5 text-[0.8125rem]
                                           font-semibold text-accent-foreground transition
                                           hover:bg-primary hover:text-primary-foreground">
                            {{ $item['action'] }}
                        </a>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Continue working --}}
        <section aria-labelledby="continue-working">
            <h3 id="continue-working" class="section-label mb-3">Continue Working</h3>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($drafts as $draft)
                    <article class="surface p-4">
                        <div class="flex items-center gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-[10px] bg-muted
                                         text-secondary-foreground">
                                <x-icon :name="$draft['icon']" :size="20" />
                            </span>

                            <div class="min-w-0">
                                <p class="text-[0.9375rem] font-semibold leading-snug text-foreground">
                                    {{ $draft['title'] }}
                                </p>
                                <p class="mt-0.5 text-[0.8125rem] text-muted-foreground">
                                    {{ $draft['subtitle'] }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between">
                            <span class="rounded-md px-2 py-1 text-[0.75rem] font-semibold
                                         {{ $tones[$draft['tone']]['badge'] }}">
                                {{ $draft['badge'] }}
                            </span>

                            <a href="#" class="inline-flex items-center gap-1.5 text-[0.8125rem] font-semibold
                                               text-primary transition hover:gap-2.5">
                                Resume
                                <x-icon name="arrow-right" :size="15" />
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>

    {{-- ============================ Right rail ============================ --}}
    <div class="space-y-8">

        {{-- Notifications --}}
        <section aria-labelledby="notifications">
            <div class="mb-3 flex items-center justify-between">
                <h3 id="notifications" class="section-label">Notifications</h3>
                <button type="button" class="text-[0.8125rem] font-semibold text-primary hover:underline">
                    Mark all read
                </button>
            </div>

            <div class="surface divide-y divide-border overflow-hidden">
                @foreach ($notifications as $notification)
                    <div class="flex items-start gap-3 p-4">
                        <span class="mt-0.5 {{ $notification['tone'] === 'green' ? 'text-emerald-500'
                            : ($notification['tone'] === 'amber' ? 'text-amber-500' : 'text-blue-500') }}">
                            <x-icon :name="$notification['icon']" :size="18" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-[0.875rem] font-medium leading-snug text-foreground">
                                {{ $notification['title'] }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">{{ $notification['time'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Recent activity --}}
        <section aria-labelledby="recent-activity">
            <div class="mb-3 flex items-center justify-between">
                <h3 id="recent-activity" class="section-label">Recent Activity</h3>
                <a href="#" class="text-[0.8125rem] font-semibold text-primary hover:underline">View all</a>
            </div>

            <div class="surface divide-y divide-border overflow-hidden">
                @foreach ($activity as $entry)
                    <div class="flex items-start gap-3 p-4">
                        <span class="w-16 shrink-0 pt-0.5 font-mono text-[0.6875rem] tabular-nums
                                     text-muted-foreground">
                            {{ $entry['time'] }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-[0.875rem] font-medium leading-snug text-foreground">
                                {{ $entry['title'] }}
                            </p>
                            <p class="mt-0.5 text-[0.875rem] font-bold text-foreground">
                                ₹{{ number_format($entry['amount']) }}
                            </p>
                        </div>

                        <span class="shrink-0 rounded-md px-2 py-0.5 text-[0.6875rem] font-semibold
                                     {{ $tones[$entry['tone']]['badge'] }}">
                            {{ $entry['source'] }}
                        </span>
                    </div>
                @endforeach

                <button type="button"
                        class="flex w-full items-center justify-center gap-2 p-4 text-[0.8125rem]
                               text-muted-foreground transition hover:bg-secondary hover:text-foreground">
                    <x-icon name="clock" :size="15" />
                    Load earlier activity
                </button>
            </div>
        </section>
    </div>
</div>

@endsection
