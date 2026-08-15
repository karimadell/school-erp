<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ERP Dashboard</title>

    @if(app()->getLocale() === 'ar')
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    @else
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    @endif

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif; }
        [dir="rtl"] body { font-family: "Cairo", sans-serif; }
        [dir="rtl"] .d-flex { flex-direction: row-reverse; }
        [dir="rtl"] aside { text-align: right; }
        [dir="rtl"] .nav { padding-right: 0; }
        [dir="rtl"] .navbar { direction: rtl; }
        aside .nav-link { border-radius: 8px; padding: 8px 10px; margin-bottom: 3px; }
        aside .nav-link:hover, aside .nav-link.active { background: rgba(255, 255, 255, 0.12); }
        aside { position: sticky; top: 0; }
    </style>
</head>
<body>
@php
    $navGroups = [
        ['label' => __('menu.academic'), 'items' => [
            ['label' => 'Учебные годы', 'icon' => '📅', 'route' => 'dashboard.academic-years.index', 'active' => 'dashboard.academic-years.*'],
            ['label' => 'Формы обучения', 'icon' => '📝', 'route' => auth()->user()?->can('manage academic years') ? 'dashboard.academic.enrollment-modes.index' : null, 'active' => 'dashboard.academic.enrollment-modes.*'],
            ['label' => 'Структура школы', 'icon' => '🏫', 'route' => 'dashboard.stages.index', 'active' => ['dashboard.stages.*', 'dashboard.grades.*', 'dashboard.classes.*']],
            ['label' => __('menu.subjects'), 'icon' => '📚', 'route' => 'dashboard.subjects.index', 'active' => 'dashboard.subjects.*'],
            ['label' => 'Учебный план', 'icon' => '📖', 'route' => 'dashboard.curricula.index', 'active' => 'dashboard.curricula.*'],
            ['label' => 'Расписание', 'icon' => '🗓', 'route' => 'filament.admin.resources.classes.index'],
            ['label' => __('menu.attendance'), 'icon' => '📋', 'route' => 'dashboard.attendance.index', 'active' => 'dashboard.attendance.*'],
            ['label' => 'Отчёт по классу', 'icon' => '📊', 'route' => 'dashboard.attendance.reports.class', 'active' => 'dashboard.attendance.reports.class'],
            ['label' => 'Отчёт по ученику', 'icon' => '📊', 'route' => 'dashboard.attendance.reports.student', 'active' => 'dashboard.attendance.reports.student'],
            ['label' => 'Панель посещаемости', 'icon' => '📈', 'route' => 'dashboard.attendance.dashboard', 'active' => 'dashboard.attendance.dashboard'],
            ['label' => 'Табели успеваемости', 'icon' => '🎓', 'route' => 'dashboard.report_cards.index', 'active' => 'dashboard.report_cards.*'],
        ]],
        ['label' => __('menu.students_section'), 'items' => [
            ['label' => 'Все ученики', 'icon' => '👨‍🎓', 'route' => 'dashboard.students.index', 'active' => 'dashboard.students.*'],
            ['label' => 'Зачисление', 'icon' => '📝', 'route' => 'dashboard.enrollments.index', 'active' => 'dashboard.enrollments.*'],
        ]],
        ['label' => 'Учителя и сотрудники', 'items' => [
            ['label' => __('menu.teachers'), 'icon' => '👨‍🏫', 'route' => 'dashboard.teachers.index', 'active' => 'dashboard.teachers.*'],
            ['label' => 'Назначения', 'icon' => '🔗', 'route' => 'filament.admin.resources.teacher-assignments.index'],
            ['label' => 'Зарплаты', 'icon' => '💵', 'route' => 'filament.admin.resources.teacher-salaries.index'],
        ]],
        ['label' => __('menu.finance'), 'items' => [
            ['label' => 'Финансовый центр', 'icon' => '🏦', 'route' => 'dashboard.finance.workspace', 'active' => 'dashboard.finance.workspace'],
            ['label' => 'Счета', 'icon' => '🧾', 'route' => 'dashboard.invoices.index', 'active' => 'dashboard.invoices.*'],
            ['label' => 'Массовое начисление', 'icon' => '💳', 'route' => auth()->user()?->can('view mass billing') ? 'dashboard.finance.mass-billing.index' : null, 'active' => 'dashboard.finance.mass-billing.*'],
            ['label' => 'Услуги и сборы', 'icon' => '💳', 'route' => 'dashboard.finance.services.index', 'active' => 'dashboard.finance.services.*'],
            ['label' => 'Цены на услуги', 'icon' => '🏷', 'route' => 'dashboard.finance.tariffs.index', 'active' => 'dashboard.finance.tariffs.*'],
            ['label' => 'Касса', 'icon' => '💰', 'route' => 'dashboard.cash.ledger', 'active' => 'dashboard.cash.ledger'],
            ['label' => 'Кассовые смены', 'icon' => '💼', 'route' => auth()->user()?->can('view cash sessions') ? 'dashboard.cash.sessions.index' : null, 'active' => 'dashboard.cash.sessions.*'],
            ['label' => 'Кассовые счета', 'icon' => '🏦', 'route' => 'dashboard.cash.accounts', 'active' => 'dashboard.cash.accounts'],
            ['label' => 'Расходы', 'icon' => '💸', 'route' => 'dashboard.cash.expenses', 'active' => 'dashboard.cash.expenses'],
            ['label' => 'Финансовые отчёты', 'icon' => '📊', 'route' => 'dashboard.cash.reports', 'active' => 'dashboard.cash.reports'],
        ]],
        ['label' => 'Транспорт', 'items' => [
            ['label' => 'Автобусы', 'icon' => '🚌', 'route' => 'filament.admin.resources.buses.index'],
        ]],
        ['label' => __('menu.administration'), 'items' => [
            ['label' => 'Пользователи', 'icon' => '👥', 'route' => 'filament.admin.resources.users.index'],
            ['label' => 'Роли и разрешения', 'icon' => '🔐', 'route' => 'filament.admin.resources.roles.index'],
            ['label' => 'Настройки школы', 'icon' => '🏫', 'route' => auth()->user()?->hasAnyRole(['super-admin', 'admin']) ? 'dashboard.settings.school.edit' : null, 'active' => 'dashboard.settings.school.*'],
            ['label' => 'Журнал действий', 'icon' => '📜', 'route' => 'dashboard.admin.audit.logs.index', 'active' => 'dashboard.admin.audit.logs.*'],
        ]],
    ];
@endphp

<div class="d-flex">
    <aside class="bg-dark text-white p-3" style="width:260px;min-width:260px;min-height:100vh;overflow-y:auto;">
        <h4 class="mb-4">{{ __('menu.dashboard') }}</h4>
        <ul class="nav flex-column">
            <li class="nav-item mb-2">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.index') ? 'active' : '' }}" href="{{ route('dashboard.index') }}">🏠 {{ __('menu.dashboard') }}</a>
            </li>
            @foreach($navGroups as $group)
                @php
                    $visibleItems = array_filter($group['items'], fn (array $item): bool => !empty($item['route']) && Route::has($item['route']));
                @endphp
                @continue(empty($visibleItems))
                <li class="text-uppercase text-secondary small mt-3 mb-1">{{ $group['label'] }}</li>
                @foreach($visibleItems as $item)
                    @php $patterns = (array) ($item['active'] ?? []); @endphp
                    <li class="nav-item">
                        <a class="nav-link text-white {{ $patterns && request()->routeIs(...$patterns) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                            {{ $item['icon'] }} {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </aside>

    <div class="flex-grow-1" style="min-width:0;">
        <nav class="navbar navbar-light bg-light border-bottom px-4">
            <div class="ms-auto d-flex gap-3">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">🌐 {{ strtoupper(app()->getLocale()) }}</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('lang.switch', 'ru') }}">🇷🇺 Русский</a></li>
                        <li><a class="dropdown-item" href="{{ route('lang.switch', 'en') }}">🇬🇧 English</a></li>
                        <li><a class="dropdown-item" href="{{ route('lang.switch', 'ar') }}">🇸🇦 العربية</a></li>
                    </ul>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown">{{ auth()->user()->name }}</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-item text-muted">{{ auth()->user()->email }}</li>
                        <li><hr></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item text-danger">🚪 {{ __('menu.logout') }}</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="container-fluid p-4">
            @if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
            @if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
            @yield('content')
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
