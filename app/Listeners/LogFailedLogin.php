<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use App\Models\AuditLog;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        AuditLog::create([
            'user_id'   => null,
            'action'    => 'login_failed',
            'model'     => 'Auth',
            'model_id'  => null,
            'old_values'=> null,
            'new_values'=> [
                'email' => $event->credentials['email'] ?? null,
            ],
            'ip'        => request()->ip(),
        ]);
    }
}