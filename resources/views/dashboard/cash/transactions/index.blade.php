@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="m-0">💰 Финансовые операции (Касса)</h3>
    </div>

    {{-- Cash Accounts Summary --}}
    <div class="row g-3 mb-4">
        @foreach($cashAccounts as $account)
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="text-muted">Касса</div>
                        <h5 class="mb-1">{{ $account->name }}</h5>
                        <div class="fw-bold fs-4 text-success">
                            {{ number_format($account->balance, 2) }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Forms --}}
    <div class="row mb-4">

        {{-- IN --}}
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    ➕ Приход (Пополнение)
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('dashboard.cash.transactions.in') }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label">Касса</label>
                            <select name="cash_account_id" class="form-select" required>
                                @foreach($cashAccounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Сумма</label>
                            <input type="number" step="0.01" name="amount" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Описание</label>
                            <input type="text" name="description" class="form-control">
                        </div>

                        <button class="btn btn-success w-100">
                            Добавить приход
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- OUT --}}
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    ➖ Расход (Списание)
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('dashboard.cash.transactions.out') }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label">Касса</label>
                            <select name="cash_account_id" class="form-select" required>
                                @foreach($cashAccounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Сумма</label>
                            <input type="number" step="0.01" name="amount" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Описание</label>
                            <input type="text" name="description" class="form-control">
                        </div>

                        <button class="btn btn-danger w-100">
                            Добавить расход
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    {{-- Transactions Table --}}
    <div class="card shadow-sm">
        <div class="card-header">
            📄 История операций
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Касса</th>
                        <th>Тип</th>
                        <th>Сумма</th>
                        <th>Описание</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $t)
                        <tr>
                            <td>{{ $t->id }}</td>
                            <td>{{ $t->cashAccount->name }}</td>
                            <td>
                                @if($t->type === 'in')
                                    <span class="badge bg-success">Приход</span>
                                @else
                                    <span class="badge bg-danger">Расход</span>
                                @endif
                            </td>
                            <td class="{{ $t->type === 'in' ? 'text-success' : 'text-danger' }}">
                                {{ number_format($t->amount, 2) }}
                            </td>
                            <td>{{ $t->description ?? '—' }}</td>
                            <td>{{ $t->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                Нет операций
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection