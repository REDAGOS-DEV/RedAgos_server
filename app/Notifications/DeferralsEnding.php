<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Donors whose temporary deferral ends today, so they may give again from
 * tomorrow, for whoever reads donors' clinical records.
 *
 * Sent each morning by the staff reminders (SendStaffReminders). Only the
 * deferrals ending that day are counted, so each is told once. It may be
 * turned off in Settings.
 */
class DeferralsEnding extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $count
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
        return [
            'category' => 'collection',
            'title' => $this->count === 1
                ? 'A donor\'s deferral ends today'
                : "{$this->count} donors' deferrals end today",
            'desc' => 'They may give again from tomorrow, and can be invited back.',
            'meta' => null,
            'icon' => 'user-check',
            'tone' => 'success',
            'action_label' => 'Open deferrals',
            'action_route' => '/blood-center/deferrals',
        ];
    }
}
