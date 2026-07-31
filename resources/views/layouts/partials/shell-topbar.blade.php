<header class="ui2-scope sticky top-0 z-10 flex h-[60px] items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 sm:px-6">
    <div class="flex items-center gap-2">
        <button
            type="button"
            class="ui2-btn-icon hidden lg:inline-flex"
            data-sidebar-collapse-toggle
            aria-label="Свернуть/развернуть боковое меню"
        >
            <x-ui-icon name="panel_left" class="h-[18px] w-[18px]" />
        </button>
        <button
            type="button"
            class="ui2-btn-icon lg:hidden"
            data-sidebar-mobile-toggle
            aria-label="Открыть меню"
        >
            <x-ui-icon name="menu" class="h-[18px] w-[18px]" />
        </button>
    </div>

    <div class="flex items-center gap-2">
        <div class="dropdown">
            <button
                type="button"
                class="ui2-btn-icon w-auto gap-1.5 px-3"
                data-bs-toggle="dropdown"
                aria-label="Выбрать язык"
            >
                <x-ui-icon name="globe" class="h-4 w-4" />
                <span class="text-xs font-semibold uppercase">{{ app()->getLocale() }}</span>
                <x-ui-icon name="chevron_down" class="h-3.5 w-3.5" />
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="{{ route('lang.switch', 'ru') }}">🇷🇺 Русский</a></li>
                <li><a class="dropdown-item" href="{{ route('lang.switch', 'en') }}">🇬🇧 English</a></li>
                <li><a class="dropdown-item" href="{{ route('lang.switch', 'ar') }}">🇸🇦 العربية</a></li>
            </ul>
        </div>

        <div class="dropdown">
            <button
                type="button"
                class="ui2-btn flex-row-reverse border-slate-200 bg-white text-slate-700 hover:bg-slate-50"
                data-bs-toggle="dropdown"
            >
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">
                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                </span>
                <span class="max-w-[10rem] truncate">{{ auth()->user()->name }}</span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-item-text text-slate-500">{{ auth()->user()->email }}</li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item flex items-center gap-2 text-red-600">
                            <x-ui-icon name="log_out" class="h-4 w-4" />
                            {{ __('menu.logout') }}
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
