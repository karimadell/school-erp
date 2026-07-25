@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <h2 class="mb-4">Roles</h2>

    <div class="card">
        <div class="card-body">

            <table class="table table-bordered table-striped">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Permissions</th>
                        <th>Users</th>
                    </tr>
                </thead>

                <tbody>

                    @forelse($roles as $role)

                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ ucfirst($role->name) }}</td>
                            <td>{{ $role->permissions_count }}</td>
                            <td>{{ $role->users_count }}</td>
                        </tr>

                    @empty

                        <tr>
                            <td colspan="4" class="text-center">No roles found</td>
                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>
    </div>

</div>

@endsection
