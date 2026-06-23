@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-4">📜 Audit Logs</h3>

    {{-- Filters --}}
    <form method="GET" class="row g-3 mb-4">
        <div class="col-md-3">
            <input type="text"
                   name="user"
                   value="{{ request('user') }}"
                   class="form-control"
                   placeholder="Search by user name">
        </div>

        <div class="col-md-3">
            <input type="text"
                   name="action"
                   value="{{ request('action') }}"
                   class="form-control"
                   placeholder="Search by action">
        </div>

        <div class="col-md-2">
            <input type="date"
                   name="from"
                   value="{{ request('from') }}"
                   class="form-control">
        </div>

        <div class="col-md-2">
            <input type="date"
                   name="to"
                   value="{{ request('to') }}"
                   class="form-control">
        </div>

        <div class="col-md-2">
            <button class="btn btn-primary w-100">
                🔍 Filter
            </button>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            🧾 System Activities
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Model</th>
                        <th>IP</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->id }}</td>

                        <td>
                            {{ $log->user?->name ?? 'System' }}
                        </td>

                        <td>
                            <span class="badge bg-secondary">
                                {{ $log->action }}
                            </span>
                        </td>

                        <td>
                            {{ $log->model ?? '-' }}
                        </td>

                        <td>
                            <code>{{ $log->ip }}</code>
                        </td>

                        <td>
                            {{ $log->created_at->format('Y-m-d H:i') }}
                            <div class="text-muted small">
                                {{ $log->created_at->diffForHumans() }}
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            No audit logs found
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            {{ $logs->links() }}
        </div>
    </div>

</div>
@endsection