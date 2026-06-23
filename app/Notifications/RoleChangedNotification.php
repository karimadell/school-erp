<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class RoleChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $newRole,
        public string $changedBy
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('🔐 Your Role Has Been Updated')
            ->greeting('Hello ' . $notifiable->name)
            ->line('Your role in the system has been updated.')
            ->line('🔑 New Role: ' . strtoupper($this->newRole))
            ->line('👤 Changed By: ' . $this->changedBy)
            ->line('If you believe this is a mistake, please contact the administrator.')
            ->salutation('Regards, ERP System');
    }
}