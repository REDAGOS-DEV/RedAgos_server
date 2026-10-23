<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The day ahead at the centre, at 7 each morning: the appointments booked for
 * today and any drive held today, for the appointment roles.
 *
 * Sent by the staff reminders (SendStaffReminders) only on a day with
 * something booked. It may be turned off in Settings.
 */
class MorningBriefing extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $drives  the names of today's drives
     */
    public function __construct(
        private readonly int $appointments,
        private readonly array $drives
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
        $booked = $this->appointments === 1 ? '1 appointment' : "{$this->appointments} appointments";
        $drives = count($this->drives);

        return [
            'category' => 'collection',
            'title' => "Today: {$booked} booked".($drives ? ($drives === 1 ? ', and a drive' : ", and {$drives} drives") : ''),
            'desc' => $drives
                ? 'Drive'.($drives === 1 ? '' : 's').' today: '.implode(', ', $this->drives).'.'
                : 'The donors booked to come in today.',
            'meta' => null,
            'icon' => 'calendar',
            'tone' => 'info',
            'action_label' => 'Open dashboard',
            'action_route' => '/blood-center/collection-dashboard',
        ];
    }
}
