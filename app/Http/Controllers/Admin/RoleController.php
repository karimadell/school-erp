<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * List roles with their permission and user counts.
     */
    public function index(): View
    {
        $roles = Role::withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();

        return view('dashboard.admin.roles.index', compact('roles'));
    }
}
