@extends('layouts.dashboard')

@section('content')
@php
    $profile = is_array($documents) ? $documents : [];
    $photoUrl = $student->photo && Storage::disk('public')->exists($student->photo) ? Storage::disk('public')->url($student->photo) : null;
    $statusLabels = ['active'=>'Активен','pre_registered'=>'Предварительная регистрация','suspended'=>'Приостановлен','graduated'=>'Выпускник'];
    $serviceLabels = [
        'tuition'=>'Обучение','tuition_regular'=>'Обучение','tuition_family'=>'Обучение','tuition_external'=>'Экстернат',
        'transport'=>'Транспорт','food'=>'Питание','uniform'=>'Школьная форма','registration'=>'Регистрационный взнос',
        'extra_classes'=>'Дополнительные занятия','activity'=>'Дополнительные занятия','other'=>'Дополнительные услуги',
    ];
@endphp

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Профиль ученика</h1><p class="text-muted mb-0">Единая карточка учебных, контактных и финансовых данных.</p></div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="{{ route('dashboard.students.edit',$student) }}">Редактировать</a>
        <a class="btn btn-outline-primary" href="{{ route('dashboard.enrollments.create',$student) }}">Перевести</a>
        @if($currentEnrollment)<a class="btn btn-outline-warning" href="{{ route('dashboard.enrollments.edit',$currentEnrollment) }}">Приостановить</a><a class="btn btn-outline-success" href="{{ route('dashboard.enrollments.edit',$currentEnrollment) }}">Выпустить</a>@endif
        <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Печать</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Счета</div><div class="fs-4 fw-bold">{{ $invoices->count() }}</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Оплачено</div><div class="fs-4 fw-bold">{{ number_format((float)$financial['paid'],2,'.',' ') }} EGP</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Задолженность</div><div class="fs-4 fw-bold text-danger">{{ number_format((float)$financial['outstanding'],2,'.',' ') }} EGP</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted">Активные услуги</div><div class="fs-4 fw-bold">{{ $subscriptions->where('status','active')->count() }}</div></div></div></div>
</div>

<ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" role="tablist">
    @foreach(['overview'=>'Обзор','parents'=>'Родители','financial'=>'Финансы','services'=>'Услуги','documents'=>'Документы','timeline'=>'История'] as $key=>$label)
        <li class="nav-item"><button class="nav-link text-nowrap {{ $loop->first?'active':'' }}" data-bs-toggle="tab" data-bs-target="#student-{{ $key }}" type="button">{{ $label }}</button></li>
    @endforeach
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="student-overview"><div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="row g-4 align-items-start">
        <div class="col-12 col-md-4 col-xl-3 text-center">
            @if($photoUrl)<img src="{{ $photoUrl }}" alt="Фотография ученика" class="rounded-circle img-fluid" style="width:180px;height:180px;object-fit:cover">@else<div class="rounded-circle bg-light border d-flex align-items-center justify-content-center mx-auto text-primary fw-bold" style="width:180px;height:180px;font-size:64px">{{ mb_substr($student->full_name ?: 'У',0,1) }}</div>@endif
            <div class="mt-3"><span class="badge {{ $student->status==='active'?'bg-success':'bg-secondary' }}">{{ $statusLabels[$student->status] ?? $student->status ?? 'Не указан' }}</span></div>
        </div>
        <div class="col-12 col-md-8 col-xl-9"><div class="row g-3">
            <div class="col-12"><div class="small text-muted">Имя на русском языке</div><div class="h4 mb-0">{{ $student->full_name }}</div></div>
            <div class="col-md-6"><div class="small text-muted">Имя на английском языке</div><div>{{ $profile['name_en'] ?? '—' }}</div></div>
            <div class="col-md-6"><div class="small text-muted">Имя на арабском языке</div><div>{{ $profile['name_ar'] ?? '—' }}</div></div>
            <div class="col-md-4"><div class="small text-muted">ID ученика</div><div>#{{ $student->id }}</div></div>
            <div class="col-md-4"><div class="small text-muted">Учебный год</div><div>{{ $currentEnrollment?->academicYear?->name ?? '—' }}</div></div>
            <div class="col-md-4"><div class="small text-muted">Ступень</div><div>{{ $currentEnrollment?->stage?->name ?? '—' }}</div></div>
            <div class="col-md-4"><div class="small text-muted">Класс</div><div>{{ $currentEnrollment?->grade?->name ?? '—' }}</div></div>
            <div class="col-md-4"><div class="small text-muted">Учебная группа</div><div>{{ $currentEnrollment?->schoolClass?->name ?? '—' }}</div></div>
            <div class="col-md-4"><div class="small text-muted">Гражданство</div><div>{{ $student->nationality ?? '—' }}</div></div>
        </div></div>
    </div></div></div></div>

    <div class="tab-pane fade" id="student-parents"><div class="row g-3">
        @foreach(['father'=>'Отец','mother'=>'Мать'] as $key=>$label) @php($contact=$profile[$key]??[])
            <div class="col-12 col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5">{{ $label }}</h2><dl class="row mb-0"><dt class="col-sm-4">ФИО</dt><dd class="col-sm-8">{{ $contact['name']??'—' }}</dd><dt class="col-sm-4">Телефон</dt><dd class="col-sm-8">{{ $contact['phone']??'—' }}</dd><dt class="col-sm-4">Email</dt><dd class="col-sm-8">{{ $contact['email']??'—' }}</dd><dt class="col-sm-4">Паспорт</dt><dd class="col-sm-8">{{ $contact['passport']??'—' }}</dd></dl></div></div></div>
        @endforeach
        <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Экстренный контакт</h2><div>{{ $profile['emergency_contact']??'Не указан' }}</div></div></div></div>
    </div></div>

    <div class="tab-pane fade" id="student-financial"><div class="card border-0 shadow-sm"><div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h2 class="h5 mb-1">Финансовое состояние</h2><span class="badge {{ $financial['status']==='clear'?'bg-success':'bg-danger' }}">{{ $financial['status']==='clear'?'Задолженности нет':'Есть задолженность' }}</span></div><div class="d-flex gap-2"><a href="{{ route('dashboard.invoices.create',['student_id'=>$student->id]) }}" class="btn btn-primary">Создать счёт</a><a href="{{ route('dashboard.invoices.index',['student_id'=>$student->id]) }}" class="btn btn-outline-primary">Открыть счета</a></div></div>
        <div class="row g-3 mb-4"><div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Начислено</div><strong>{{ number_format((float)$financial['invoiced'],2,'.',' ') }} EGP</strong></div></div><div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Оплачено</div><strong>{{ number_format((float)$financial['paid'],2,'.',' ') }} EGP</strong></div></div><div class="col-md-4"><div class="border rounded p-3"><div class="text-muted small">Текущий остаток</div><strong>{{ number_format((float)$financial['outstanding'],2,'.',' ') }} EGP</strong></div></div></div>
        <h3 class="h6">Счета</h3><div class="table-responsive mb-4"><table class="table align-middle"><thead><tr><th>Номер</th><th>Дата</th><th>Итого</th><th>Оплачено</th><th>Остаток</th><th>Статус</th></tr></thead><tbody>@forelse($invoices as $invoice)<tr><td><a href="{{ route('dashboard.invoices.show',$invoice) }}">{{ $invoice->display_number }}</a></td><td>{{ $invoice->created_at?->format('d.m.Y') }}</td><td>{{ number_format((float)$invoice->total_amount,2,'.',' ') }} EGP</td><td>{{ number_format((float)$invoice->paid_amount,2,'.',' ') }} EGP</td><td>{{ number_format((float)$invoice->remaining_amount,2,'.',' ') }} EGP</td><td>{{ ['unpaid'=>'Не оплачен','partial'=>'Частично оплачен','paid'=>'Оплачен'][$invoice->status]??$invoice->status }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">Счетов нет.</td></tr>@endforelse</tbody></table></div>
        <h3 class="h6">Платежи</h3><div class="table-responsive mb-4"><table class="table align-middle"><thead><tr><th>Номер</th><th>Дата</th><th>Сумма</th><th>Способ</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td>{{ $payment->payment_number??'—' }}</td><td>{{ ($payment->paid_at??$payment->created_at)?->format('d.m.Y H:i') }}</td><td>{{ number_format((float)$payment->amount,2,'.',' ') }} EGP</td><td>{{ $payment->payment_method??'—' }}</td></tr>@empty<tr><td colspan="4" class="text-center text-muted">Платежей нет.</td></tr>@endforelse</tbody></table></div>
        <h3 class="h6">Предстоящие платежи</h3>@forelse($financial['upcoming'] as $invoice)<div class="d-flex justify-content-between border rounded p-3 mb-2"><span>{{ $invoice->display_number }} · до {{ $invoice->due_date?->format('d.m.Y') }}</span><strong>{{ number_format((float)$invoice->remaining_amount,2,'.',' ') }} EGP</strong></div>@empty<div class="text-muted">Предстоящих платежей нет.</div>@endforelse
    </div></div></div>

    <div class="tab-pane fade" id="student-services"><div class="row g-3">@forelse($subscriptions as $subscription) @php($tariff=$subscription->profile_tariff)
        <div class="col-12 col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h2 class="h6">{{ $serviceLabels[$subscription->fee?->category]??$subscription->fee?->name_ru??'Услуга' }}</h2><span class="badge {{ $subscription->status==='active'?'bg-success':'bg-secondary' }}">{{ ['active'=>'Активна','suspended'=>'Приостановлена','cancelled'=>'Отменена','completed'=>'Завершена'][$subscription->status]??$subscription->status }}</span></div><div class="small text-muted mb-3">{{ $subscription->fee?->name_ru }}</div><dl class="row small mb-0"><dt class="col-5">Начало</dt><dd class="col-7">{{ $subscription->start_date?->format('d.m.Y')??'—' }}</dd><dt class="col-5">Окончание</dt><dd class="col-7">{{ $subscription->end_date?->format('d.m.Y')??'—' }}</dd><dt class="col-5">Тариф</dt><dd class="col-7">{{ $tariff?number_format((float)$tariff->amount,2,'.',' ').' EGP':'—' }}</dd><dt class="col-5">Период</dt><dd class="col-7">{{ ['monthly'=>'Ежемесячно','yearly'=>'Ежегодно','daily'=>'Ежедневно','once'=>'Однократно','package'=>'Пакет'][$tariff?->payment_period??'']??($tariff?->payment_period??'—') }}</dd></dl></div></div></div>
    @empty<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">Активные подписки отсутствуют.</div></div></div>@endforelse</div></div>

    <div class="tab-pane fade" id="student-documents"><div class="card border-0 shadow-sm"><div class="card-body p-4">
        <div class="row g-3 mb-4">
            @foreach(['identity_document'=>'Свидетельство о рождении / паспорт','medical'=>'Медицинские документы','photos'=>'Фотографии','other'=>'Другие вложения'] as $key=>$label)
                <div class="col-12 col-md-6"><div class="border rounded p-3 h-100"><h2 class="h6">{{ $label }}</h2>@php($value=$profile[$key]??null) @if(filled($value) && !is_array($value) && !$documentAttachments->contains(fn($file)=>$file['name']===basename((string)$value)))<div>{{ $value }}</div>@elseif(blank($value))<div class="text-muted">Не загружено.</div>@else<div class="text-muted">Файл доступен ниже.</div>@endif</div></div>
            @endforeach
        </div>
        <h2 class="h6">Файлы</h2><div class="list-group">@forelse($documentAttachments as $file)<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3" target="_blank" href="{{ $file['url'] }}"><span><strong>{{ $file['label'] }}</strong><br><small class="text-muted">{{ $file['name'] }}</small></span><span class="btn btn-sm btn-outline-primary">Просмотреть / скачать</span></a>@empty<div class="text-muted">Файлы не загружены.</div>@endforelse</div>
    </div></div></div>

    <div class="tab-pane fade" id="student-timeline"><div class="card border-0 shadow-sm"><div class="card-body p-4"><div class="list-group list-group-flush">@forelse($timeline as $event)<div class="list-group-item px-0 py-3" data-timeline-at="{{ $event['at']->toISOString() }}"><div class="d-flex justify-content-between gap-3"><strong>{{ $event['type'] }}</strong><span class="small text-muted text-nowrap">{{ $event['at']->format('d.m.Y H:i') }}</span></div><div class="text-muted mt-1">{{ $event['text'] ?: '—' }}</div></div>@empty<div class="text-center text-muted py-5">История пока отсутствует.</div>@endforelse</div></div></div></div>
</div>
@endsection
