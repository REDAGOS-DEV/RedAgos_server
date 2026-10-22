<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Units on the shelf that expire within a few days, so they are issued first.
 *
 * Sent each morning by the staff reminders (SendStaffReminders) while there
 * are any. Inventory viewers may turn it off in Settings.
 */
class UnitsExpiringSoon extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $count,
        private readonly int $days
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
        $units = $this->count === 1 ? '1 unit' : "{$this->count} units";

        return [
            'category' => 'inventory',
            'title' => "{$units} expiring within {$this->days} days",
            'desc' => 'Available units close to their expiry date. Issue these first.',
            'meta' => null,
            'icon' => 'clock',
            'tone' => 'warning',
            'action_label' => 'Open inventory',
            'action_route' => '/blood-center/inventory',
        ];
    }
}
