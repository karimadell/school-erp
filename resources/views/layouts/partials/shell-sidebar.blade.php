@php
    /**
     * Modern UI v2. Same route-safety contract as v1: every entry names a
     * route (checked with Route::has() before it's ever rendered — a bad
     * name here would otherwise break every page sharing this layout) or a
     * raw href for a cross-panel link into Filament. Entries whose target
     * doesn't exist yet (or is a known-broken page, per the Modern UI/UX
     * readiness report) are simply omitted.
     */
    $navGroups = [
        [
            'label' => 'Главная',
            'items' => [
                ['label' => 'Панель управления', 'icon' => 'layout_dashboard', 'route' => 'dashboard.index', 'active' => 'dashboard.index'],
            ],
        ],
        [
            'label' => 'Учебный процесс',
            'items' => [
                ['label' => 'Учебные годы', 'icon' => 'calendar_days', 'route' => 'dashboard.academic-years.index', 'active' => 'dashboard.academic-years.*'],
                ['label' => 'Формы обучения', 'icon' => 'notebook_text', 'route' => auth()->user()?->can('manage academic years') ? 'dashboard.academic.enrollment-modes.index' : null, 'active' => 'dashboard.academic.enrollment-modes.*'],
                // Четверти: Quarter model exists but has no management UI yet — hidden.
                ['label' => 'Структура школы', 'icon' => 'school', 'route' => 'dashboard.stages.index', 'active' => 'dashboard.stages.*'],
                ['label' => 'Предметы', 'icon' => 'book_open', 'route' => 'dashboard.subjects.index', 'active' => 'dashboard.subjects.*'],
                ['label' => 'Учебный план', 'icon' => 'notebook_text', 'route' => 'dashboard.curricula.index', 'active' => 'dashboard.curricula.*'],
                ['label' => 'Расписание', 'icon' => 'calendar_clock', 'href' => Route::has('filament.admin.resources.classes.index') ? route('filament.admin.resources.classes.index') : null],
                ['label' => 'Посещаемость', 'icon' => 'clipboard_list', 'route' => 'dashboard.attendance.index', 'active' => 'dashboard.attendance.*'],
                ['label' => 'Отчёт по классу', 'icon' => 'bar_chart_3', 'route' => 'dashboard.attendance.reports.class', 'active' => 'dashboard.attendance.reports.class', 'indent' => true],
                ['label' => 'Отчёт по ученику', 'icon' => 'bar_chart_3', 'route' => 'dashboard.attendance.reports.student', 'active' => 'dashboard.attendance.reports.student', 'indent' => true],
                ['label' => 'Панель посещаемости', 'icon' => 'activity', 'route' => 'dashboard.attendance.dashboard', 'active' => 'dashboard.attendance.dashboard', 'indent' => true],
                // Экзамены: controller has zero implementation — hidden.
                ['label' => 'Табели успеваемости', 'icon' => 'graduation_cap', 'route' => 'dashboard.report_cards.index', 'active' => 'dashboard.report_cards.*'],
            ],
        ],
        [
            'label' => 'Ученики',
            'items' => [
                ['label' => 'Все ученики', 'icon' => 'users', 'route' => 'dashboard.students.index', 'active' => 'dashboard.students.*'],
                ['label' => 'Зачисление', 'icon' => 'user_plus', 'route' => 'dashboard.enrollments.index', 'active' => 'dashboard.enrollments.*'],
                // Родители: no model/UI exists yet — hidden.
                // Документы: StudentFileController exists but isn't routed anywhere — hidden.
            ],
        ],
        [
            'label' => 'Учителя и сотрудники',
            'items' => [
                ['label' => 'Учителя', 'icon' => 'graduation_cap', 'route' => 'dashboard.teachers.index', 'active' => 'dashboard.teachers.*'],
                ['label' => 'Назначения', 'icon' => 'link_2', 'href' => Route::has('filament.admin.resources.teacher-assignments.index') ? route('filament.admin.resources.teacher-assignments.index') : null],
                // Квалификации: only exists embedded in the Teacher edit page — no standalone page to link.
                ['label' => 'Зарплаты', 'icon' => 'wallet', 'href' => Route::has('filament.admin.resources.teacher-salaries.index') ? route('filament.admin.resources.teacher-salaries.index') : null],
                // Сотрудники: no non-teaching-staff concept exists yet — hidden.
            ],
        ],
        [
            'label' => 'Финансы',
            'items' => [
                ['label' => 'Финансовый центр', 'icon' => 'landmark', 'route' => 'dashboard.finance.workspace', 'active' => 'dashboard.finance.workspace'],
                ['label' => 'Счета', 'icon' => 'receipt', 'route' => 'dashboard.invoices.index', 'active' => 'dashboard.invoices.*'],
                ['label' => 'Массовое начисление', 'icon' => 'banknote', 'route' => auth()->user()?->can('view mass billing') ? 'dashboard.finance.mass-billing.index' : null, 'active' => 'dashboard.finance.mass-billing.*'],
                ['label' => 'Услуги и сборы', 'icon' => 'credit_card', 'route' => 'dashboard.finance.services.index', 'active' => 'dashboard.finance.services.*'],
                ['label' => 'Цены на услуги', 'icon' => 'payments', 'route' => 'dashboard.finance.tariffs.index', 'active' => 'dashboard.finance.tariffs.*'],
                // Платежи: no standalone page — payments happen as an action from within Счета.
                ['label' => 'Касса', 'icon' => 'landmark', 'route' => 'dashboard.cash.ledger', 'active' => 'dashboard.cash.ledger'],
                ['label' => 'Кассовые счета', 'icon' => 'briefcase', 'route' => 'dashboard.cash.accounts', 'active' => 'dashboard.cash.accounts'],
                ['label' => 'Расходы', 'icon' => 'trending_down', 'route' => 'dashboard.cash.expenses', 'active' => 'dashboard.cash.expenses'],
                ['label' => 'Финансовые отчёты', 'icon' => 'pie_chart', 'route' => 'dashboard.cash.reports', 'active' => 'dashboard.cash.reports'],
            ],
        ],
        [
            'label' => 'Транспорт',
            'items' => [
                ['label' => 'Автобусы', 'icon' => 'school', 'href' => Route::has('filament.admin.resources.buses.index') ? route('filament.admin.resources.buses.index') : null],
            ],
        ],
        [
            'label' => 'Администрирование',
            'items' => [
                ['label' => 'Пользователи', 'icon' => 'user_cog', 'href' => Route::has('filament.admin.resources.users.index') ? route('filament.admin.resources.users.index') : null],
                ['label' => 'Роли и разрешения', 'icon' => 'shield_check', 'href' => Route::has('filament.admin.resources.roles.index') ? route('filament.admin.resources.roles.index') : null],
                ['label' => 'Настройки школы', 'icon' => 'school', 'route' => auth()->user()?->hasAnyRole(['super-admin', 'admin']) ? 'dashboard.settings.school.edit' : null, 'active' => 'dashboard.settings.school.*'],
                // Языки: already available via the topbar language switcher, not a separate admin page.
                ['label' => 'Журнал действий', 'icon' => 'scroll_text', 'route' => 'dashboard.admin.audit.logs.index', 'active' => 'dashboard.admin.audit.logs.*'],
                // Резервные копии: spatie/laravel-backup is installed but has no admin-facing UI yet — hidden.
            ],
        ],
    ];
@endphp

<div class="ui2-sidebar-backdrop" data-sidebar-backdrop></div>

<aside class="ui2-sidebar ui2-scope">
    <div class="flex h-[60px] shrink-0 items-center gap-2 border-b border-white/10 px-4 text-white">
        <span class="text-lg" aria-hidden="true">🎓</span>
        <span class="ui2-sidebar-hide-when-collapsed truncate text-sm font-semibold">{{ config('app.name') }}</span>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-3" aria-label="Основная навигация">
        @foreach ($navGroups as $group)
            @php
                $visibleItems = array_filter($group['items'], fn ($item) => !empty($item['route']) || !empty($item['href']));
            @endphp

            @continue(empty($visibleItems))

            <div class="ui2-sidebar-hide-when-collapsed px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                {{ $group['label'] }}
            </div>

            @foreach ($visibleItems as $item)
                @php
                    $href = $item['href'] ?? (Route::has($item['route']) ? route($item['route']) : null);
                    $isActive = isset($item['active']) && request()->routeIs($item['active']);
                @endphp

                @continue(!$href)

                <a
                    href="{{ $href }}"
                    class="ui2-sidebar-link {{ $isActive ? 'is-active' : '' }} {{ !empty($item['indent']) ? 'ms-4 text-[13px] opacity-85' : '' }}"
                >
                    <x-ui-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0" />
                    <span class="ui2-sidebar-hide-when-collapsed truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>
</aside>
