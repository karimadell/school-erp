<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use App\Models\AuditLog;

class LogSuccessfulLogin
{
    public function handle(Login $event): void
    {
        AuditLog::create([
            'user_id'   => $event->user->id,
            'action'    => 'login',
            'model'     => 'Auth',
            'model_id'  => $event->user->id,
            'old_values'=> null,
            'new_values'=> [
                'email' => $event->user->email,
            ],
            'ip'        => request()->ip(),
        ]);
    }
}