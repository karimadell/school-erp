@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <h3 class="mb-3">✏️ Edit User</h3>

    <form method="POST" action="{{ route('dashboard.admin.users.update', $user) }}">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label>Name</label>
            <input name="name" class="form-control" value="{{ $user->name }}" required>
        </div>

        <div class="mb-3">
            <label>Email</label>
            <input name="email" type="email" class="form-control" value="{{ $user->email }}" required>
        </div>

        <div class="mb-3">
            <label>Role</label>
            <select name="role" class="form-select" required>
                @foreach($roles as $role)
                    <option value="{{ $role->name }}"
                        @selected($user->roles->first()?->name === $role->name)>
                        {{ ucfirst($role->name) }}
                    </option>
                @endforeach
            </select>
        </div>

        <button class="btn btn-success">Update</button>
        <a href="{{ route('dashboard.admin.users.index') }}" class="btn btn-secondary">Cancel</a>
    </form>

</div>
@endsection