@php
    /**
     * Modern UI v2. Same route-safety contract as v1: every entry names a
     * route (checked with Route::has() before it's ever rendered — a bad
     * name here would otherwise break every page sharing this layout) or a
     * raw href for a cross-panel link into Filament. Entries whose target
     * doesn't exist yet (or is a known-broken page, per the Modern UI/UX
     * readiness report) are simply omitted.
     */
    $adminPanel = \Filament\Facades\Filament::getPanel('admin');
    $canAccessAdminPanel = auth()->user()?->canAccessPanel($adminPanel) ?? false;

    $navGroups = [
        [
            'label' => __('menu.home'),
            'items' => [
                ['label' => __('menu.dashboard'), 'icon' => 'layout_dashboard', 'route' => 'dashboard.index', 'active' => 'dashboard.index'],
                // Phase 1 navigation fix: this used to link straight into
                // the Filament /admin panel ($adminPanel->getUrl()), which
                // took operational users out of the dashboard shell
                // entirely. It now opens a dashboard-native administration
                // hub instead (see AdministrationController) — Filament is
                // still reachable from there, but only as an explicitly
                // labelled technical fallback, not a silent redirect.
                // Pre-UAT fix: visibility is gated on $canAccessAdminPanel
                // (the same administrative-role + active check
                // EnsureAdministrativePortalAccess enforces on the route
                // itself) — previously this entry had no guard at all in
                // the partial and relied solely on route middleware to
                // keep unauthorized/inactive users off the destination
                // page, which left the link itself visible to anyone.
                ['label' => __('menu.administration'), 'icon' => 'shield_check', 'route' => $canAccessAdminPanel ? 'dashboard.administration.index' : null, 'active' => 'dashboard.administration.*'],
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
                // Phase 1: dashboard-native counterparts of the Filament
                // BellScheduleResource / AcademicCalendarResource /
                // ClassroomResource — same 'view timetable'/'manage
                // timetable' permission gate as those resources.
                ['label' => __('bell_schedule.navigation'), 'icon' => 'clock', 'route' => auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ? 'dashboard.bell-schedules.index' : null, 'active' => 'dashboard.bell-schedules.*'],
                ['label' => __('academic_calendar.navigation'), 'icon' => 'calendar_range', 'route' => auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ? 'dashboard.academic-calendars.index' : null, 'active' => 'dashboard.academic-calendars.*'],
                ['label' => __('classroom.navigation'), 'icon' => 'building_2', 'route' => auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ? 'dashboard.classrooms.index' : null, 'active' => 'dashboard.classrooms.*'],
                ['label' => 'Расписание', 'icon' => 'calendar_clock', 'route' => 'dashboard.classes.index', 'active' => 'dashboard.classes.*'],
                ['label' => __('timetable.school_wide_navigation'), 'icon' => 'calendar_range', 'route' => auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']) ? 'dashboard.school-timetable.index' : null, 'active' => 'dashboard.school-timetable.*'],
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
                // Pre-UAT fix: these two remain Filament-backed (out of
                // Phase 1 scope) but must not be shown to a user who
                // couldn't actually open them. "Назначения" mirrors
                // TeacherAssignmentResource's real gate — Filament resolves
                // it from TeacherAssignmentPolicy::viewAny(), which is
                // 'manage teachers' (app/Policies/TeacherAssignmentPolicy.php).
                ['label' => 'Назначения', 'icon' => 'link_2', 'href' => (Route::has('filament.admin.resources.teacher-assignments.index') && (auth()->user()?->can('manage teachers') ?? false)) ? route('filament.admin.resources.teacher-assignments.index') : null],
                // Квалификации: only exists embedded in the Teacher edit page — no standalone page to link.
                ['label' => __('teacher_salary.navigation'), 'icon' => 'wallet', 'href' => (Route::has('filament.admin.resources.teacher-salaries.index') && (auth()->user()?->can('view payroll') ?? false)) ? route('filament.admin.resources.teacher-salaries.index') : null],
                // Сотрудники: no non-teaching-staff concept exists yet — hidden.
            ],
        ],
        [
            'label' => 'Финансы',
            'items' => [
                ['label' => __('finance_uat.student_finance'), 'icon' => 'landmark', 'route' => auth()->user()?->can('view invoices') ? 'dashboard.finance.workspace' : null, 'active' => 'dashboard.finance.workspace'],
                ['label' => __('finance_uat.invoices'), 'icon' => 'receipt', 'route' => auth()->user()?->can('view invoices') ? 'dashboard.invoices.index' : null, 'active' => 'dashboard.invoices.*'],
                ['label' => 'Массовое начисление', 'icon' => 'banknote', 'route' => auth()->user()?->can('view mass billing') ? 'dashboard.finance.mass-billing.index' : null, 'active' => 'dashboard.finance.mass-billing.*'],
                ['label' => __('finance_uat.services_and_fees'), 'icon' => 'credit_card', 'route' => auth()->user()?->can('manage fees') ? 'dashboard.finance.services.index' : null, 'active' => 'dashboard.finance.services.*'],
                ['label' => __('finance_uat.service_prices'), 'icon' => 'payments', 'route' => auth()->user()?->can('manage fee prices') ? 'dashboard.finance.tariffs.index' : null, 'active' => 'dashboard.finance.tariffs.*'],
                // Платежи: no standalone page — payments happen as an action from within Счета.
                ['label' => 'Касса', 'icon' => 'landmark', 'route' => 'dashboard.cash.ledger', 'active' => 'dashboard.cash.ledger'],
                ['label' => 'Кассовые смены', 'icon' => 'briefcase', 'route' => auth()->user()?->can('view cash sessions') ? 'dashboard.cash.sessions.index' : null, 'active' => 'dashboard.cash.sessions.*'],
                ['label' => 'Кассовые счета', 'icon' => 'briefcase', 'route' => 'dashboard.cash.accounts', 'active' => 'dashboard.cash.accounts'],
                ['label' => __('finance_uat.expenses'), 'icon' => 'trending_down', 'href' => (Route::has('filament.admin.resources.expenses.index') && (auth()->user()?->can('manage expenses') ?? false)) ? route('filament.admin.resources.expenses.index') : null],
                ['label' => __('finance_uat.financial_reports'), 'icon' => 'pie_chart', 'route' => auth()->user()?->can('view cash reports') ? 'dashboard.cash.reports' : null, 'active' => 'dashboard.cash.reports'],
            ],
        ],
        [
            'label' => 'Транспорт',
            'items' => [
                // Pre-UAT fix: BusResource has no dedicated policy/permission
                // either — same reasoning as "Зарплаты" above, so the real
                // authorization boundary is admin-panel access.
                ['label' => 'Автобусы', 'icon' => 'school', 'href' => (Route::has('filament.admin.resources.buses.index') && $canAccessAdminPanel) ? route('filament.admin.resources.buses.index') : null],
            ],
        ],
        [
            'label' => 'Администрирование',
            'items' => [
                // Pre-UAT fix: Users / Roles & Permissions used to link
                // straight into Filament from the main sidebar with no
                // authorization check beyond Route::has(). They are now
                // reached exclusively through the controlled
                // /dashboard/administration hub (AdministrationController),
                // which already gates them on 'manage users' / 'manage
                // roles' — removed here rather than duplicating that gate
                // in two places.
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
