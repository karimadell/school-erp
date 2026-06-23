<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">

        {{-- Logo --}}
        <a class="navbar-brand" href="{{ route('dashboard.index') }}">
            ERP
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">

            {{-- LEFT MENU --}}
            <ul class="navbar-nav me-auto">

                {{-- Dashboard (ظاهر للجميع بعد تسجيل الدخول) --}}
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('dashboard.index') }}">
                        🏠 Dashboard
                    </a>
                </li>

                {{-- Invoices --}}
                @can('manage invoices')
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('dashboard.invoices.index') }}">
                            🧾 Invoices
                        </a>
                    </li>
                @endcan

                {{-- Cash Transactions --}}
                @can('manage cash')
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('dashboard.cash.transactions.index') }}">
                            💵 Cash Transactions
                        </a>
                    </li>
                @endcan

                {{-- Cash Reports --}}
                @can('view cash reports')
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('dashboard.cash.reports') }}">
                            📊 Cash Reports
                        </a>
                    </li>
                @endcan

            </ul>

            {{-- RIGHT MENU --}}
            <ul class="navbar-nav ms-auto">

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        {{ auth()->user()->name }}
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="dropdown-item" type="submit">
                                    🚪 Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>

            </ul>
        </div>
    </div>
</nav>