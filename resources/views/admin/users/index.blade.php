@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-4">👥 Users Management</h3>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th width="220">Change Role</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="badge bg-primary">
                                {{ $user->roles->first()?->name ?? '—' }}
                            </span>
                        </td>
                        <td>
                            <form method="POST"
                                  action="{{ route('admin.users.role', $user) }}"
                                  class="d-flex gap-2">
                                @csrf
                                <select name="role" class="form-select form-select-sm">
                                    @foreach($roles as $role)
                                        <option value="{{ $role->name }}"
                                            @selected($user->hasRole($role->name))>
                                            {{ ucfirst($role->name) }}
                                        </option>
                                    @endforeach
                                </select>
                                <button class="btn btn-sm btn-success">
                                    Save
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection