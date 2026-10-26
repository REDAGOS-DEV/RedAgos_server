<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Counselling referrals nobody has acted on a few days after they were made.
 *
 * Sent once a day by the staff reminders (SendStaffReminders), for the
 * referrals that reached that age that day, so each is chased once. Names no
 * donor and no result: who needs counselling is the referral page's to show.
 */
class ReferralsWaiting extends Notification
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
        $donors = $this->count === 1 ? '1 donor' : "{$this->count} donors";

        return [
            'category' => 'laboratory',
            'title' => 'Counselling referrals waiting',
            'desc' => "{$donors} referred {$this->days} days ago ".($this->count === 1 ? 'has' : 'have').' not been contacted yet.',
            'meta' => null,
            'icon' => 'life-buoy',
            'tone' => 'warning',
            'action_label' => 'Open referrals',
            'action_route' => '/blood-center/counselling-referrals',
        ];
    }
}
