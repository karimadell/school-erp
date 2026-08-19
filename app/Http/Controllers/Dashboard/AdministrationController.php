<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Phase 1 navigation fix: the sidebar's top-level "Администрирование" item
 * used to link straight into the Filament /admin panel root
 * ($adminPanel->getUrl()), so any administrative-portal user who clicked it
 * left the dashboard shell entirely. This page keeps that click inside
 * /dashboard: it links to every administration destination that already
 * exists there, and — only for users who can reach the Filament panel at
 * all — lists the handful of destinations (Users, Roles & permissions)
 * intentionally not migrated in this phase, clearly labelled as a
 * technical fallback rather than a silent redirect.
 */
class AdministrationController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $adminPanel = \Filament\Facades\Filament::getPanel('admin');
        $canAccessAdminPanel = $user?->canAccessPanel($adminPanel) ?? false;

        return view('dashboard.administration.index', [
            'canManageSchoolSettings' => $user?->hasAnyRole(['super-admin', 'admin']) ?? false,
            'canViewAuditLogs' => $user?->can('view audit logs') ?? false,
            'canViewTimetableInfrastructure' => $user?->hasAnyPermission(['view timetable', 'manage timetable']) ?? false,
            'canManageUsers' => $canAccessAdminPanel && ($user?->can('manage users') ?? false) && Route::has('filament.admin.resources.users.index'),
            'canManageRoles' => $canAccessAdminPanel && ($user?->can('manage roles') ?? false) && Route::has('filament.admin.resources.roles.index'),
            'canAccessAdminPanel' => $canAccessAdminPanel,
            'adminPanelUrl' => $canAccessAdminPanel ? $adminPanel->getUrl() : null,
        ]);
    }
}
