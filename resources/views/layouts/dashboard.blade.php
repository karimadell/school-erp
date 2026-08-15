<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('menu.dashboard') }}</title>

    @if(app()->getLocale() == 'ar')
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    @else
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    @endif

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- Modern UI v2: Tailwind (Preflight disabled, see tailwind.config.js) --}}
    @vite(['resources/css/dashboard-v2.css'])
</head>

<body>

<div class="ui2-shell lg:flex" data-shell>

    @include('layouts.partials.shell-sidebar')

    <div class="flex min-w-0 flex-1 flex-col">

        @include('layouts.partials.shell-topbar')

        <div class="p-4 sm:p-6">

            @if(session('success'))
                <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
            @endif

            @if(session('error'))
                <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            @yield('content')

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    (function () {
        var shell = document.querySelector('[data-shell]');
        if (!shell) {
            return;
        }

        var STORAGE_KEY = 'ui-sidebar-collapsed';

        if (localStorage.getItem(STORAGE_KEY) === '1') {
            shell.classList.add('is-collapsed');
        }

        var collapseBtn = document.querySelector('[data-sidebar-collapse-toggle]');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function () {
                shell.classList.toggle('is-collapsed');
                localStorage.setItem(STORAGE_KEY, shell.classList.contains('is-collapsed') ? '1' : '0');
            });
        }

        var mobileBtn = document.querySelector('[data-sidebar-mobile-toggle]');
        if (mobileBtn) {
            mobileBtn.addEventListener('click', function () {
                shell.classList.toggle('is-mobile-open');
            });
        }

        var backdrop = document.querySelector('[data-sidebar-backdrop]');
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                shell.classList.remove('is-mobile-open');
            });
        }
    })();
</script>

@stack('scripts')

</body>
</html>
