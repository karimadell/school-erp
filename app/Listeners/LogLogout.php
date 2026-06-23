<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;
use App\Models\AuditLog;

class LogLogout
{
    public function handle(Logout $event): void
    {
        AuditLog::create([
            'user_id'   => $event->user->id ?? null,
            'action'    => 'logout',
            'model'     => 'Auth',
            'model_id'  => $event->user->id ?? null,
            'old_values'=> null,
            'new_values'=> [
                'email' => $event->user->email ?? null,
            ],
            'ip'        => request()->ip(),
        ]);
    }
}