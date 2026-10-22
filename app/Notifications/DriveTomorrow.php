<?php

namespace App\Notifications;

use App\Models\MobileEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A mobile drive the centre holds tomorrow, for whoever runs drives.
 *
 * Sent the morning before by the staff reminders (SendStaffReminders). Staff
 * who run drives may turn it off in Settings.
 */
class DriveTomorrow extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MobileEvent $drive
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $start = $this->drive->start_time ? date('g:i A', strtotime($this->drive->start_time)) : null;

        return [
            'category' => 'collection',
            'title' => 'Blood drive tomorrow: '.$this->drive->name,
            'desc' => $this->drive->location.($start ? ', from '.$start : '').'.',
            'meta' => null,
            'icon' => 'route',
            'tone' => 'info',
            'action_label' => 'Open drives',
            'action_route' => '/blood-center/drives',
            'mobile_event_id' => $this->drive->id,
        ];
    }
}
