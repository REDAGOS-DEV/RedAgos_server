<?php

namespace App\Notifications;

use App\Models\CorrectionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A correction filed in a department, waiting for its head's decision.
 *
 * Sent by CorrectionService when one is filed and not applied on the spot (a
 * head's own correction is). In-app only, like the centre's other notices.
 */
class CorrectionAwaitingApproval extends Notification
{
    use Queueable;

    public function __construct(
        private readonly CorrectionRequest $correction,
        private readonly string $requesterName
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
            'category' => 'correction',
            'title' => 'Correction to approve',
            'desc' => $this->requesterName.' asked to correct the '.$this->correction->subject->label().'.',
            'meta' => 'Reason: '.$this->correction->reason,
            'icon' => 'pencil',
            'tone' => 'warning',
            'action_label' => 'Review',
            'action_route' => '/blood-center/corrections',
            'correction_id' => $this->correction->id,
        ];
    }
}
