<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RoleChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $oldRole,
        public string $newRole,
        public string $changedBy
    ) {}

    public function build()
    {
        return $this
            ->subject('🔐 Your account role has been updated')
            ->view('emails.role-changed');
    }
}