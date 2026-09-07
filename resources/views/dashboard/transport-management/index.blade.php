@extends('layouts.dashboard')

@section('content')
@php
    $canVehicles = auth()->user()->can(\App\Support\TransportPermissions::MANAGE_VEHICLES);
    $canRoutes = auth()->user()->can(\App\Support\TransportPermissions::MANAGE_ROUTES);
    $canAssignments = auth()->user()->can(\App\Support\TransportPermissions::MANAGE_ASSIGNMENTS);
    $roleLabels = ['driver' => 'Водитель', 'supervisor' => 'Ответственный', 'staff_passenger' => 'Сотрудник-пассажир'];
    $weekdayLabels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
    $activeRoutes = $routes->where('is_active', true);
    $activeBuses = $buses->where('is_active', true);
@endphp

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="mb-1">Управление трансфером</h2>
            <p class="text-muted mb-0">Автобусы, маршруты и назначения учеников и сотрудников. Тарифы хранятся в Финансах.</p>
        </div>
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
            <div><label class="form-label small mb-1">Учебный год</label><select name="academic_year_id" class="form-select form-select-sm">
                @foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((int)$yearId === $year->id)>{{ $year->name }}</option>@endforeach
            </select></div>
            <div><label class="form-label small mb-1">Дата состояния</label><input type="date" name="date" value="{{ $date->toDateString() }}" class="form-control form-control-sm"></div>
            <button class="btn btn-outline-primary btn-sm">Показать</button>
        </form>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><strong>Операция не выполнена.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" role="tablist">
        @foreach(['overview'=>'Обзор','vehicles'=>'Транспорт','routes'=>'Маршруты','students'=>'Ученики','staff'=>'Сотрудники','history'=>'История назначений'] as $id=>$label)
            <li class="nav-item"><button class="nav-link text-nowrap {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#transport-{{ $id }}" type="button">{{ $label }}</button></li>
        @endforeach
    </ul>

    <div class="tab-content">
        <section id="transport-overview" class="tab-pane fade show active">
            <div class="row g-3">
                @foreach([
                    ['Активные автобусы',$kpis['vehicles'],'primary'], ['Всего ученических мест',$kpis['capacity'],'secondary'],
                    ['Занято мест',$kpis['occupied'],'info'], ['Свободно мест',$kpis['available'],'success'],
                    ['Ученики на трансфере',$kpis['students'],'primary'], ['Заполненные автобусы',$kpis['full'],'danger'],
                ] as [$label,$value,$color])
                    <div class="col-6 col-lg-2"><div class="card h-100 border-{{ $color }}"><div class="card-body"><div class="text-muted small">{{ $label }}</div><div class="fs-3 fw-semibold">{{ $value }}</div></div></div></div>
                @endforeach
            </div>
            <div class="alert alert-light border mt-3 mb-0">Сотрудники учитываются отдельно и не занимают ученические места. Рабочий лимит — не более 14 учеников на автобус.</div>
        </section>

        <section id="transport-vehicles" class="tab-pane fade">
            @if($canVehicles)
            <div class="card mb-3"><div class="card-header fw-semibold">Добавить транспорт</div><div class="card-body">
                <form method="POST" action="{{ route('dashboard.transport-management.vehicles.store') }}" class="row g-2 align-items-end">@csrf
                    <div class="col-md-2"><label class="form-label">Код</label><input name="vehicle_code" class="form-control"></div>
                    <div class="col-md-3"><label class="form-label">Название</label><input name="name" class="form-control"></div>
                    <div class="col-md-2"><label class="form-label">Госномер *</label><input name="plate_number" required class="form-control"></div>
                    <div class="col-6 col-md-2"><label class="form-label">Мест ученикам</label><input name="student_capacity" type="number" min="1" max="14" value="14" required class="form-control"></div>
                    <div class="col-6 col-md-2"><label class="form-label">Всего мест</label><input name="passenger_capacity" type="number" min="1" value="15" required class="form-control"></div>
                    <div class="col-md-1"><button class="btn btn-primary w-100">+</button></div>
                </form>
            </div></div>
            @endif
            <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Транспорт</th><th>Госномер</th><th>Места</th><th>Текущие маршруты</th><th>Сотрудники на дату</th><th>Статус</th><th></th></tr></thead><tbody>
                @forelse($buses as $bus)
                @php
                    $used=(int)($occupiedByBus[$bus->id]??0); $limit=min(14,$bus->student_capacity);
                    $currentRoutes=$bus->studentTransportAssignments->filter(fn($a)=>$a->effective_from->lte($date)&&(!$a->effective_to||$a->effective_to->gte($date)))->pluck('route.name')->filter()->unique();
                    $currentStaff=$bus->staffAssignments->filter(fn($a)=>$a->effective_from->lte($date)&&(!$a->effective_to||$a->effective_to->gte($date))&&($a->weekdays===null||in_array($date->dayOfWeekIso,$a->weekdays,true)));
                @endphp
                <tr>
                    <td><strong>{{ $bus->vehicle_code ?: '—' }}</strong><div class="small text-muted">{{ $bus->name ?: 'Без названия' }}</div></td><td>{{ $bus->plate_number }}</td>
                    <td><span class="badge bg-{{ $used >= $limit ? 'danger' : 'success' }}">{{ $used }} / {{ $limit }} мест</span><div class="small text-muted">Всего: {{ $bus->passenger_capacity }}</div></td>
                    <td>{{ $currentRoutes->implode(', ') ?: '—' }}</td><td>@forelse($currentStaff as $item)<div class="small">{{ $roleLabels[$item->role] }}: {{ $item->user?->name }}</div>@empty—@endforelse @if($bus->driver_name)<div class="small text-muted">Архивный водитель: {{ $bus->driver_name }}</div>@endif</td>
                    <td><span class="badge bg-{{ $bus->is_active?'success':'secondary' }}">{{ $bus->is_active?'Активен':'Неактивен' }}</span></td>
                    <td class="text-nowrap">
                        <a href="#transport-students" class="btn btn-sm btn-outline-secondary">Ученики</a>
                        @if($canVehicles)<details class="d-inline-block"><summary class="btn btn-sm btn-outline-primary">Изменить</summary><form method="POST" action="{{ route('dashboard.transport-management.vehicles.update',$bus) }}" class="border rounded bg-white p-3 position-absolute shadow" style="z-index:10;min-width:300px">@csrf @method('PUT')
                            <input name="vehicle_code" value="{{ $bus->vehicle_code }}" placeholder="Код" class="form-control form-control-sm mb-2"><input name="name" value="{{ $bus->name }}" placeholder="Название" class="form-control form-control-sm mb-2"><input name="plate_number" value="{{ $bus->plate_number }}" required class="form-control form-control-sm mb-2"><div class="d-flex gap-2"><input name="student_capacity" type="number" min="1" max="14" value="{{ $bus->student_capacity }}" required class="form-control form-control-sm"><input name="passenger_capacity" type="number" value="{{ $bus->passenger_capacity }}" required class="form-control form-control-sm"></div><button class="btn btn-primary btn-sm mt-2">Сохранить</button>
                        </form></details>
                        <form method="POST" action="{{ route('dashboard.transport-management.vehicles.active',$bus) }}" class="d-inline">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $bus->is_active?0:1 }}"><button class="btn btn-sm btn-outline-{{ $bus->is_active?'danger':'success' }}">{{ $bus->is_active?'Отключить':'Включить' }}</button></form>@endif
                    </td>
                </tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">Транспорт пока не добавлен.</td></tr>@endforelse
            </tbody></table></div></div>
        </section>

        <section id="transport-routes" class="tab-pane fade">
            @if($canRoutes)<div class="card mb-3"><div class="card-header fw-semibold">Добавить физический маршрут</div><div class="card-body"><form method="POST" action="{{ route('dashboard.transport-management.routes.store') }}" class="row g-2 align-items-end">@csrf
                <div class="col-md-4"><label class="form-label">Название *</label><input name="name" required class="form-control"></div><div class="col-md-2"><label class="form-label">Тарифная зона</label><input name="pricing_zone" class="form-control" placeholder="Можно не указывать"></div><div class="col-md-5"><label class="form-label">Описание</label><input name="description" class="form-control"></div><div class="col-md-1"><button class="btn btn-primary w-100">+</button></div>
            </form><div class="form-text">Тарифная зона — только метаданные маршрута. Цена здесь не задаётся.</div></div></div>@endif
            <div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Маршрут</th><th>Тарифная зона</th><th>Описание</th><th>Автобусы</th><th>Ученики</th><th>Статус</th><th></th></tr></thead><tbody>
                @forelse($routes as $route)<tr><td class="fw-semibold">{{ $route->name }}</td><td>{{ $route->pricing_zone ?: 'Не задана' }}</td><td>{{ $route->description ?: '—' }}</td><td>{{ $buses->filter(fn($b)=>$b->studentTransportAssignments->contains(fn($a)=>$a->transport_route_id===$route->id&&$a->effective_from->lte($date)&&(!$a->effective_to||$a->effective_to->gte($date))))->pluck('vehicle_code')->filter()->implode(', ') ?: '—' }}</td><td>{{ $route->active_students_count }}</td><td><span class="badge bg-{{ $route->is_active?'success':'secondary' }}">{{ $route->is_active?'Активен':'Неактивен' }}</span></td><td class="text-nowrap">
                    @if($canRoutes)<details class="d-inline-block"><summary class="btn btn-sm btn-outline-primary">Изменить</summary><form method="POST" action="{{ route('dashboard.transport-management.routes.update',$route) }}" class="border rounded bg-white p-3 position-absolute shadow" style="z-index:10;min-width:320px">@csrf @method('PUT')<input name="name" value="{{ $route->name }}" required class="form-control form-control-sm mb-2"><input name="pricing_zone" value="{{ $route->pricing_zone }}" placeholder="Тарифная зона" class="form-control form-control-sm mb-2"><textarea name="description" class="form-control form-control-sm mb-2">{{ $route->description }}</textarea><button class="btn btn-primary btn-sm">Сохранить</button></form></details><form method="POST" action="{{ route('dashboard.transport-management.routes.active',$route) }}" class="d-inline">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $route->is_active?0:1 }}"><button class="btn btn-sm btn-outline-{{ $route->is_active?'danger':'success' }}">{{ $route->is_active?'Отключить':'Включить' }}</button></form>@endif
                </td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">Маршруты пока не добавлены.</td></tr>@endforelse
            </tbody></table></div></div>
        </section>

        <section id="transport-students" class="tab-pane fade">
            @if($canAssignments)<div class="card mb-3"><div class="card-header fw-semibold">Назначить транспорт ученику</div><div class="card-body"><form method="POST" action="{{ route('dashboard.transport-management.student-assignments.store') }}" class="row g-2 align-items-end">@csrf
                <div class="col-lg-3"><label class="form-label">Активное зачисление *</label><select name="enrollment_id" required class="form-select"><option value="">Выберите ученика</option>@foreach($enrollments->whereNotIn('id',$currentByEnrollment->keys()) as $enrollment)<option value="{{ $enrollment->id }}">{{ $enrollment->student?->name }} — {{ $enrollment->schoolClass?->name ?? $enrollment->grade?->name }}</option>@endforeach</select></div>
                <div class="col-lg-2"><label class="form-label">Тарифная зона</label><input name="pricing_zone" class="form-control"></div><div class="col-lg-2"><label class="form-label">Маршрут *</label><select name="transport_route_id" required class="form-select"><option value="">—</option>@foreach($activeRoutes as $route)<option value="{{ $route->id }}">{{ $route->name }}{{ $route->pricing_zone?' · '.$route->pricing_zone:'' }}</option>@endforeach</select></div>
                <div class="col-lg-2"><label class="form-label">Транспорт *</label><select name="bus_id" required class="form-select"><option value="">—</option>@foreach($activeBuses as $bus) @php $used=(int)($occupiedByBus[$bus->id]??0);$limit=min(14,$bus->student_capacity); @endphp<option value="{{ $bus->id }}" @disabled($used >= $limit)>{{ $bus->vehicle_code ?: $bus->name }} — {{ $used }}/{{ $limit }}{{ $used >= $limit?' (мест нет)':' ('.($limit-$used).' свободно)' }}</option>@endforeach</select></div>
                <div class="col-lg-2"><label class="form-label">Место посадки</label><input name="pickup_point" class="form-control"></div><div class="col-lg-1"><label class="form-label">С даты *</label><input name="effective_from" type="date" value="{{ $date->toDateString() }}" required class="form-control"></div><div class="col-12"><label class="form-label">Примечание</label><input name="change_reason" class="form-control"><button class="btn btn-primary mt-2">Назначить</button></div>
            </form></div></div>@endif
            <form method="GET" class="d-flex gap-2 mb-2"><input type="hidden" name="academic_year_id" value="{{ $yearId }}"><input name="student" value="{{ request('student') }}" class="form-control" placeholder="Поиск ученика"><button class="btn btn-outline-secondary">Найти</button></form>
            <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Ученик</th><th>Класс</th><th>Зона</th><th>Маршрут</th><th>Транспорт</th><th>Посадка</th><th>Начало</th><th>Статус</th><th></th></tr></thead><tbody>
                @forelse($enrollments as $enrollment) @php $assignment=$currentByEnrollment->get($enrollment->id); @endphp<tr><td>{{ $enrollment->student?->name }}</td><td>{{ $enrollment->schoolClass?->name ?? $enrollment->grade?->name ?? '—' }}</td><td>{{ $assignment?->pricing_zone ?: '—' }}</td><td>{{ $assignment?->route?->name ?: '—' }}</td><td>{{ $assignment?->bus?->vehicle_code ?: $assignment?->bus?->name ?: '—' }}</td><td>{{ $assignment?->pickup_point ?: '—' }}</td><td>{{ $assignment?->effective_from?->format('d.m.Y') ?: '—' }}</td><td><span class="badge bg-{{ $assignment?'success':'secondary' }}">{{ $assignment?'Назначен':'Не назначен' }}</span></td><td class="text-nowrap">
                    @if($assignment && $canAssignments)
                    <details class="d-inline-block"><summary class="btn btn-sm btn-outline-primary">Изменить</summary><form method="POST" action="{{ route('dashboard.transport-management.student-assignments.transfer',$assignment) }}" class="border rounded bg-white p-3 position-absolute shadow" style="z-index:10;min-width:360px">@csrf<div class="small mb-2"><strong>Сейчас:</strong> {{ $assignment->route?->name }}, {{ $assignment->bus?->vehicle_code }}, {{ $assignment->pricing_zone ?: 'без зоны' }}, {{ $assignment->pickup_point ?: 'без точки' }}</div><div class="alert alert-warning small py-2">Смена тарифной зоны требует проверки Финансов и заблокирована в этой фазе.</div><input name="pricing_zone" value="{{ $assignment->pricing_zone }}" readonly class="form-control form-control-sm mb-2"><select name="transport_route_id" required class="form-select form-select-sm mb-2">@foreach($activeRoutes as $route)<option value="{{ $route->id }}" @selected($route->id===$assignment->transport_route_id)>{{ $route->name }}</option>@endforeach</select><select name="bus_id" required class="form-select form-select-sm mb-2">@foreach($activeBuses as $bus)<option value="{{ $bus->id }}" @selected($bus->id===$assignment->bus_id)>{{ $bus->vehicle_code ?: $bus->name }} — {{ (int)($occupiedByBus[$bus->id]??0) }}/{{ min(14,$bus->student_capacity) }}</option>@endforeach</select><input name="pickup_point" value="{{ $assignment->pickup_point }}" placeholder="Место посадки" class="form-control form-control-sm mb-2"><input name="effective_from" type="date" required class="form-control form-control-sm mb-2"><input name="change_reason" required placeholder="Причина" class="form-control form-control-sm mb-2"><button class="btn btn-primary btn-sm">Сохранить перевод</button></form></details>
                    <details class="d-inline-block"><summary class="btn btn-sm btn-outline-danger">Остановить</summary><form method="POST" action="{{ route('dashboard.transport-management.student-assignments.end',$assignment) }}" class="border rounded bg-white p-3 position-absolute shadow" style="z-index:10;min-width:300px">@csrf<div class="alert alert-warning small py-2">Счета и возвраты автоматически не изменяются.</div><input name="effective_to" type="date" required class="form-control form-control-sm mb-2"><input name="change_reason" required placeholder="Причина" class="form-control form-control-sm mb-2"><button class="btn btn-danger btn-sm">Завершить</button></form></details>@endif
                    <a href="{{ route('dashboard.transport-management.index',['academic_year_id'=>$yearId,'student'=>$enrollment->student?->name]) }}#transport-history" class="btn btn-sm btn-outline-secondary">История</a>
                </td></tr>@empty<tr><td colspan="9" class="text-center text-muted py-4">Активные зачисления не найдены.</td></tr>@endforelse
            </tbody></table></div></div>
        </section>

        <section id="transport-staff" class="tab-pane fade">
            @if($canAssignments)<div class="card mb-3"><div class="card-header fw-semibold">Назначить сотрудника</div><div class="card-body"><form method="POST" action="{{ route('dashboard.transport-management.staff-assignments.store') }}" class="row g-2 align-items-end">@csrf
                <div class="col-md-3"><label class="form-label">Транспорт *</label><select name="bus_id" required class="form-select">@foreach($activeBuses as $bus)<option value="{{ $bus->id }}">{{ $bus->vehicle_code ?: $bus->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Сотрудник *</label><select name="user_id" required class="form-select">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">Роль *</label><select name="role" required class="form-select">@foreach($roleLabels as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">С даты *</label><input name="effective_from" type="date" required class="form-control"></div><div class="col-md-2"><label class="form-label">По дату</label><input name="effective_to" type="date" class="form-control"></div>
                <div class="col-12"><label class="form-label d-block">Дни недели <span class="text-muted small">(не выбраны — ежедневно)</span></label>@foreach($weekdayLabels as $value=>$label)<label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekdays[]" value="{{ $value }}"><span class="form-check-label">{{ $label }}</span></label>@endforeach</div><div class="col-md-10"><input name="change_reason" placeholder="Примечание" class="form-control"></div><div class="col-md-2"><button class="btn btn-primary w-100">Назначить</button></div>
            </form></div></div>@endif
            <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Транспорт</th><th>Сотрудник</th><th>Роль</th><th>Период</th><th>Дни</th><th>Статус</th><th></th></tr></thead><tbody>
                @forelse($staffAssignments as $item) @php $current=$item->effective_from->lte($date)&&(!$item->effective_to||$item->effective_to->gte($date)); @endphp<tr><td>{{ $item->bus?->vehicle_code ?: $item->bus?->name }}</td><td>{{ $item->user?->name }}</td><td>{{ $roleLabels[$item->role]??$item->role }}</td><td>{{ $item->effective_from->format('d.m.Y') }} — {{ $item->effective_to?->format('d.m.Y')??'∞' }}</td><td>{{ $item->weekdays ? collect($item->weekdays)->map(fn($d)=>$weekdayLabels[$d])->implode(', ') : 'Ежедневно' }}</td><td><span class="badge bg-{{ $current?'success':'secondary' }}">{{ $current?'Действует':'Не действует' }}</span></td><td>
                    @if($canAssignments && !$item->effective_to)<details class="d-inline-block"><summary class="btn btn-sm btn-outline-primary">Изменить</summary><form method="POST" action="{{ route('dashboard.transport-management.staff-assignments.change',$item) }}" class="border rounded bg-white p-3 position-absolute shadow" style="z-index:10;min-width:360px">@csrf<select name="bus_id" class="form-select form-select-sm mb-2">@foreach($activeBuses as $bus)<option value="{{ $bus->id }}" @selected($bus->id===$item->bus_id)>{{ $bus->vehicle_code?:$bus->name }}</option>@endforeach</select><select name="user_id" class="form-select form-select-sm mb-2">@foreach($users as $user)<option value="{{ $user->id }}" @selected($user->id===$item->user_id)>{{ $user->name }}</option>@endforeach</select><select name="role" class="form-select form-select-sm mb-2">@foreach($roleLabels as $value=>$label)<option value="{{ $value }}" @selected($value===$item->role)>{{ $label }}</option>@endforeach</select><input name="effective_from" type="date" required class="form-control form-control-sm mb-2"><input name="effective_to" type="date" class="form-control form-control-sm mb-2"><div class="mb-2">@foreach($weekdayLabels as $value=>$label)<label class="me-2 small"><input type="checkbox" name="weekdays[]" value="{{ $value }}" @checked($item->weekdays&&in_array($value,$item->weekdays,true))> {{ $label }}</label>@endforeach</div><input name="change_reason" required placeholder="Причина" class="form-control form-control-sm mb-2"><button class="btn btn-primary btn-sm">Сохранить изменение</button></form></details><details class="d-inline-block"><summary class="btn btn-sm btn-outline-danger">Завершить</summary><form method="POST" action="{{ route('dashboard.transport-management.staff-assignments.end',$item) }}" class="border rounded bg-white p-3 position-absolute shadow" style="z-index:10;min-width:280px">@csrf<input name="effective_to" type="date" required class="form-control form-control-sm mb-2"><input name="change_reason" required placeholder="Причина" class="form-control form-control-sm mb-2"><button class="btn btn-danger btn-sm">Завершить</button></form></details>@endif
                </td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">Назначений сотрудников пока нет.</td></tr>@endforelse
            </tbody></table></div></div>
        </section>

        <section id="transport-history" class="tab-pane fade">
            <form method="GET" class="card card-body mb-3"><div class="row g-2 align-items-end"><div class="col-md-2"><label class="form-label">Учебный год</label><select name="academic_year_id" class="form-select">@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((int)$yearId===$year->id)>{{ $year->name }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">Транспорт</label><select name="bus_id" class="form-select"><option value="">Все</option>@foreach($buses as $bus)<option value="{{ $bus->id }}" @selected(request('bus_id')==$bus->id)>{{ $bus->vehicle_code?:$bus->name }}</option>@endforeach</select></div><div class="col-md-2"><label class="form-label">Маршрут</label><select name="route_id" class="form-select"><option value="">Все</option>@foreach($routes as $route)<option value="{{ $route->id }}" @selected(request('route_id')==$route->id)>{{ $route->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Ученик</label><input name="student" value="{{ request('student') }}" class="form-control"></div><div class="col-md-2"><label class="form-label">Действовало на дату</label><input name="history_date" type="date" value="{{ request('history_date') }}" class="form-control"></div><div class="col-md-1"><button class="btn btn-outline-primary w-100">Найти</button></div></div></form>
            <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Ученик / класс</th><th>Маршрут / транспорт</th><th>Зона</th><th>Посадка</th><th>Период</th><th>Статус</th><th>Кто создал / завершил</th><th>Причина</th></tr></thead><tbody>@forelse($history as $item)<tr><td>{{ $item->enrollment?->student?->name }}<div class="small text-muted">{{ $item->enrollment?->schoolClass?->name ?: '—' }}</div></td><td>{{ $item->route?->name }}<div class="small text-muted">{{ $item->bus?->vehicle_code?:$item->bus?->name }}</div></td><td>{{ $item->pricing_zone?:'—' }}</td><td>{{ $item->pickup_point?:'—' }}</td><td>{{ $item->effective_from->format('d.m.Y') }} — {{ $item->effective_to?->format('d.m.Y')??'∞' }}</td><td>{{ $item->status==='active'?'Действует':'Завершено' }}</td><td>{{ $item->creator?->name?:'—' }}<div class="small text-muted">{{ $item->endedBy?->name?:'—' }}</div></td><td>{{ $item->change_reason?:'—' }}</td></tr>@empty<tr><td colspan="8" class="text-center text-muted py-4">История не найдена.</td></tr>@endforelse</tbody></table></div></div><div class="mt-3">{{ $history->links() }}</div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const showHashTab = () => {
        const hash = window.location.hash;
        if (!hash) return;
        const trigger = document.querySelector(`[data-bs-target="${hash}"]`);
        if (trigger && window.bootstrap) bootstrap.Tab.getOrCreateInstance(trigger).show();
    };
    showHashTab();
    window.addEventListener('hashchange', showHashTab);
});
</script>
@endpush
