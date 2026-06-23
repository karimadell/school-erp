<aside class="sidebar">

<ul class="menu">

<li class="{{ request()->routeIs('dashboard.index') ? 'active' : '' }}">
    <a href="{{ route('dashboard.index') }}">
        📊 {{ __('app.dashboard') }}
    </a>
</li>

<li class="menu-section">{{ __('app.academic') }}</li>

<li>
<a href="{{ route('dashboard.stages.index') }}">
🏫 {{ __('app.stages') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.grades.index') }}">
🎓 {{ __('app.grades') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.classes.index') }}">
🏛 {{ __('app.classes') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.classrooms.index') }}">
🚪 {{ __('app.class_rooms') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.academic-years.index') }}">
📅 {{ __('app.academic_years') }}
</a>
</li>

<li class="menu-section">{{ __('app.students') }}</li>

<li>
<a href="{{ route('dashboard.students.index') }}">
👨‍🎓 {{ __('app.students') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.enrollments.index') }}">
📝 {{ __('app.enrollments') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.attendance.index') }}">
📋 {{ __('app.attendance') }}
</a>
</li>

<li class="menu-section">{{ __('app.finance') }}</li>

<li>
<a href="{{ route('dashboard.invoices.index') }}">
💰 {{ __('app.invoices') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.cash.transactions') }}">
💳 {{ __('app.cash_transactions') }}
</a>
</li>

<li>
<a href="#" >
🟢 {{ __('app.income') }}
</a>
</li>

<li>
<a href="#">
🔴 {{ __('app.expenses') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.cash.transfer.form') }}">
🔁 {{ __('app.cash_transfer') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.cash.reports') }}">
📈 {{ __('app.cash_reports') }}
</a>
</li>

<li class="menu-section">{{ __('app.services') }}</li>

<li>
<a href="#">
🍽 {{ __('app.meal_plans') }}
</a>
</li>

<li>
<a href="#">
👕 {{ __('app.uniform_products') }}
</a>
</li>

<li>
<a href="#">
📚 {{ __('app.extra_classes') }}
</a>
</li>

<li class="menu-section">{{ __('app.transport') }}</li>

<li>
<a href="#">
🚌 {{ __('app.bus_routes') }}
</a>
</li>

<li class="menu-section">{{ __('app.administration') }}</li>

<li>
<a href="{{ route('dashboard.admin.users.index') }}">
👤 {{ __('app.users') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.admin.roles.index') }}">
🔐 {{ __('app.roles') }}
</a>
</li>

<li>
<a href="{{ route('dashboard.admin.audit-logs.index') }}">
📜 {{ __('app.audit_logs') }}
</a>
</li>

</ul>

</aside>