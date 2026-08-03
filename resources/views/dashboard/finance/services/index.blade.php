@extends('layouts.dashboard')
@section('content')
<div class="container-fluid py-4"><div class="d-flex justify-content-between align-items-center mb-3"><div><h2>Услуги и сборы</h2><p class="text-muted mb-0">Каталог услуг отделён от истории цен.</p></div><a class="btn btn-primary" href="{{ route('dashboard.finance.services.create') }}">Добавить услугу</a></div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Услуга</th><th>Категория</th><th>Статус</th><th>Тарифов</th><th>Текущая цена</th><th>Будущая цена</th><th>Последнее изменение</th></tr></thead><tbody>
@forelse($services as $fee) @php $current=$fee->prices->first(fn($p)=>$p->status()==='current'); $future=$fee->prices->filter(fn($p)=>$p->status()==='future')->sortBy('start_date')->first(); @endphp
<tr><td><a href="{{ route('dashboard.finance.services.show',$fee) }}">{{ $fee->name_ru }}</a></td><td>{{ $fee->category }}</td><td><span class="badge bg-{{ $fee->is_active?'success':'secondary' }}">{{ $fee->is_active?'Активен':'Неактивен' }}</span></td><td>{{ $fee->prices_count }}</td><td>{{ $current ? number_format($current->amount,2).' EGP' : 'Не задана' }}</td><td>{{ $future ? number_format($future->amount,2).' EGP с '.$future->start_date->format('d.m.Y') : '—' }}</td><td>{{ $fee->prices->max('created_at')?->format('d.m.Y') ?? '—' }}</td></tr>
@empty<tr><td colspan="7" class="text-center text-muted py-4">Услуги пока не созданы.</td></tr>@endforelse
</tbody></table></div></div>{{ $services->links() }}</div>
@endsection
