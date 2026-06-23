@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-4">🛡 Roles & Permissions</h3>

    @foreach($roles as $role)
    <div class="card mb-3">
        <div class="card-header fw-bold">
            {{ ucfirst($role->name) }}
        </div>

        <div class="card-body">
            <form method="POST"
                  action="{{ route('admin.roles.permissions', $role) }}">
                @csrf

                <div class="row">
                    @foreach($permissions as $permission)
                    <div class="col-md-4 mb-2">
                        <label class="form-check">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="permissions[]"
                                   value="{{ $permission->name }}"
                                   @checked($role->hasPermissionTo($permission->name))>
                            {{ $permission->name }}
                        </label>
                    </div>
                    @endforeach
                </div>

                <button class="btn btn-primary mt-3">
                    Save Permissions
                </button>
            </form>
        </div>
    </div>
    @endforeach

</div>
@endsection