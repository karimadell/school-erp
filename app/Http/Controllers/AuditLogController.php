<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->get('from');
        $to   = $request->get('to');
        $user = $request->get('user');
        $action = $request->get('action');

        $logs = AuditLog::with('user')
            ->when($from, fn($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('created_at', '<=', $to))
            ->when($user, fn($q) => $q->where('user_id', $user))
            ->when($action, fn($q) => $q->where('action', 'like', "%$action%"))
            ->latest()
            ->paginate(20);

        return view('dashboard.audit-logs.index', compact(
            'logs',
            'from',
            'to',
            'user',
            'action'
        ));
    }
}