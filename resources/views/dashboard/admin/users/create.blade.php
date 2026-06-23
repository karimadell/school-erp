@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-3">➕ Create User</h3>

    <form method="POST" action="{{ route('dashboard.admin.users.store') }}">
        @csrf

        <div class="mb-3">
            <label>Name</label>
            <input name="name" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Email</label>
            <input name="email" type="email" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Password</label>
            <input name="password" type="password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Role</label>
            <select name="role" class="form-select" required>
                @foreach($roles as $role)
                    <option value="{{ $role->name }}">{{ ucfirst($role->name) }}</option>
                @endforeach
            </select>
        </div>

        <button class="btn btn-success">Save</button>
        <a href="{{ route('dashboard.admin.users.index') }}" class="btn btn-secondary">Cancel</a>
    </form>

</div>
@endsection