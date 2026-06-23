<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>ERP Dashboard</title>

    @if(app()->getLocale() == 'ar')
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    @else
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    @endif

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif;
        }

        [dir="rtl"] body {
            font-family: "Cairo", sans-serif;
        }

        [dir="rtl"] .d-flex {
            flex-direction: row-reverse;
        }

        [dir="rtl"] aside {
            text-align: right;
        }

        [dir="rtl"] .nav {
            padding-right: 0;
        }

        [dir="rtl"] .navbar {
            direction: rtl;
        }

        aside .nav-link {
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 3px;
        }

        aside .nav-link:hover,
        aside .nav-link.active {
            background: rgba(255, 255, 255, 0.12);
        }

        aside {
            position: sticky;
            top: 0;
        }
    </style>
</head>

<body>

<div class="d-flex">

    <!-- Sidebar -->
    <aside class="bg-dark text-white p-3" style="width:260px;min-height:100vh;overflow-y:auto;">

        <h4 class="mb-4">{{ __('menu.dashboard') }}</h4>

        <ul class="nav flex-column">

            {{-- Dashboard --}}
            <li class="nav-item mb-2">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.index') ? 'active' : '' }}"
                   href="{{ route('dashboard.index') }}">
                    🏠 {{ __('menu.dashboard') }}
                </a>
            </li>

            {{-- Academic --}}
            <li class="text-uppercase text-secondary small mt-3 mb-1">
                {{ __('menu.academic') }}
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.stages.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.stages.index') }}">
                    🏫 {{ __('menu.stages') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.grades.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.grades.index') }}">
                    🎓 {{ __('menu.grades') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.classes.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.classes.index') }}">
                    🏛 {{ __('menu.classes') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.subjects.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.subjects.index') }}">
                    📚 {{ __('menu.subjects') ?? 'Subjects' }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.teachers.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.teachers.index') }}">
                    👨‍🏫 {{ __('menu.teachers') ?? 'Teachers' }}
                </a>
            </li>

            {{-- Students --}}
            <li class="text-uppercase text-secondary small mt-3 mb-1">
                {{ __('menu.students_section') }}
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.students.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.students.index') }}">
                    👨‍🎓 {{ __('menu.students') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.enrollments.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.enrollments.index') }}">
                    📝 {{ __('menu.enrollments') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.attendance.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.attendance.index') }}">
                    📋 {{ __('menu.attendance') }}
                </a>
            </li>

            {{-- Grades --}}
            <li class="text-uppercase text-secondary small mt-3 mb-1">
                {{ __('student_grades.title') }}
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.student-grades.index') || request()->routeIs('dashboard.student-grades.create') || request()->routeIs('dashboard.student-grades.edit') ? 'active' : '' }}"
                   href="{{ route('dashboard.student-grades.index') }}">
                    📊 {{ __('student_grades.title') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.student-grades.bulk.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.student-grades.bulk.form') }}">
                    ⚡ {{ __('student_grades.bulk_entry') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.student-grades.report.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.student-grades.report.form') }}">
                    🖨 {{ __('student_grades.print_report') }}
                </a>
            </li>

            {{-- Finance --}}
            <li class="text-uppercase text-secondary small mt-3 mb-1">
                {{ __('menu.finance') }}
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.invoices.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.invoices.index') }}">
                    🧾 {{ __('menu.invoices') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.fees.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.fees.index') }}">
                    💳 {{ __('menu.services') }}
                </a>
            </li>

            {{-- Cash System --}}
            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.cash.accounts') ? 'active' : '' }}"
                   href="{{ route('dashboard.cash.accounts') }}">
                    🏦 Accounts
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.cash.income') ? 'active' : '' }}"
                   href="{{ route('dashboard.cash.income') }}">
                    💵 Income
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.cash.expenses') ? 'active' : '' }}"
                   href="{{ route('dashboard.cash.expenses') }}">
                    💸 Expenses
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.cash.transactions') ? 'active' : '' }}"
                   href="{{ route('dashboard.cash.transactions') }}">
                    💰 Transactions
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.cash.reports') ? 'active' : '' }}"
                   href="{{ route('dashboard.cash.reports') }}">
                    📊 Reports
                </a>
            </li>

            {{-- Restaurant --}}
            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.reports.restaurant') ? 'active' : '' }}"
                   href="{{ route('dashboard.reports.restaurant') }}">
                    🍽 Restaurant
                </a>
            </li>

            {{-- Transport --}}
            <li class="text-uppercase text-secondary small mt-3 mb-1">
                🚍 Transport
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.transport.index') ? 'active' : '' }}"
                   href="{{ route('dashboard.transport.index') }}">
                    🚌 Routes
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.transport.subscriptions') ? 'active' : '' }}"
                   href="{{ route('dashboard.transport.subscriptions') }}">
                    👨‍🎓 Subscriptions
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.transport.report') ? 'active' : '' }}"
                   href="{{ route('dashboard.transport.report') }}">
                    📊 Transport Report
                </a>
            </li>

            {{-- Administration --}}
            <li class="text-uppercase text-secondary small mt-3 mb-1">
                {{ __('menu.administration') }}
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.admin.users.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.admin.users.index') }}">
                    👥 {{ __('menu.users') }}
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link text-white {{ request()->routeIs('dashboard.admin.roles.*') ? 'active' : '' }}"
                   href="{{ route('dashboard.admin.roles.index') }}">
                    🔐 {{ __('menu.roles') }}
                </a>
            </li>

        </ul>

    </aside>

    <!-- Main -->
    <div class="flex-grow-1">

        <nav class="navbar navbar-light bg-light border-bottom px-4">

            <div class="ms-auto d-flex gap-3">

                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                        🌐 {{ strtoupper(app()->getLocale()) }}
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="{{ route('lang.switch','ru') }}">🇷🇺 Русский</a></li>
                        <li><a class="dropdown-item" href="{{ route('lang.switch','en') }}">🇬🇧 English</a></li>
                        <li><a class="dropdown-item" href="{{ route('lang.switch','ar') }}">🇸🇦 العربية</a></li>
                    </ul>
                </div>

                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown">
                        {{ auth()->user()->name }}
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-item text-muted">{{ auth()->user()->email }}</li>
                        <li><hr></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item text-danger">
                                    🚪 {{ __('menu.logout') }}
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>

            </div>

        </nav>

        <div class="container-fluid p-4">

            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            @yield('content')

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

@stack('scripts')

</body>
</html>