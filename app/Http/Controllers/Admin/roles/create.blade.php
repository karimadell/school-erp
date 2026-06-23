@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-3">➕ Create Role</h3>

    <form method="POST" action="{{ route('dashboard.admin.roles.store') }}">
        @csrf

        <div class="mb-3">
            <label>Role Name</label>
            <input name="name" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Permissions</label>
            <div class="row">
                @foreach($permissions as $permission)
                    <div class="col-md-4">
                        <label>
                            <input type="checkbox" name="permissions[]"
                                   value="{{ $permission->name }}">
                            {{ $permission->name }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <button class="btn btn-success">Save</button>
        <a href="{{ route('dashboard.admin.roles.index') }}" class="btn btn-secondary">Cancel</a>
    </form>

</div>
@endsection