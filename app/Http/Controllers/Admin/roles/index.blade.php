@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>🔐 Roles & Permissions</h3>
        <a href="{{ route('dashboard.admin.roles.create') }}" class="btn btn-primary">
            ➕ Add Role
        </a>
    </div>

    <div class="card">
        <table class="table table-bordered mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Role</th>
                    <th>Permissions</th>
                    <th width="160">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach($roles as $role)
                <tr>
                    <td><strong>{{ ucfirst($role->name) }}</strong></td>
                    <td>
                        @foreach($role->permissions as $permission)
                            <span class="badge bg-secondary mb-1">
                                {{ $permission->name }}
                            </span>
                        @endforeach
                    </td>
                    <td>
                        <a href="{{ route('dashboard.admin.roles.edit', $role) }}"
                           class="btn btn-sm btn-outline-primary">
                            Edit
                        </a>

                        <form method="POST"
                              action="{{ route('dashboard.admin.roles.destroy', $role) }}"
                              class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete role?')">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

</div>
@endsection