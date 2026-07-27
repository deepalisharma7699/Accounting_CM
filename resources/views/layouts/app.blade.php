<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- data-page selects which module resources/js/app.js boots. --}}
<body class="h-full" data-page="@yield('page', 'dashboard')">

    @include('partials.sidebar')

    <div class="flex min-h-full flex-col lg:pl-sidebar">
        @include('partials.topbar', ['heading' => $heading ?? 'Home'])

        <main class="flex-1 px-4 py-8 sm:px-8">
            @yield('content')
        </main>
    </div>

</body>
</html>
