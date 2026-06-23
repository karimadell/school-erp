@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-3">✏️ Edit Role: {{ ucfirst($role->name) }}</h3>

    <form method="POST" action="{{ route('dashboard.admin.roles.update', $role) }}">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label>Permissions</label>
            <div class="row">
                @foreach($permissions as $permission)
                    <div class="col-md-4">
                        <label>
                            <input type="checkbox" name="permissions[]"
                                   value="{{ $permission->name }}"
                                   @checked($role->hasPermissionTo($permission->name))>
                            {{ $permission->name }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <button class="btn btn-success">Update</button>
        <a href="{{ route('dashboard.admin.roles.index') }}" class="btn btn-secondary">Cancel</a>
    </form>

</div>
@endsection