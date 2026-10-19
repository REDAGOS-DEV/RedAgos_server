<?php

namespace App\Notifications;

use App\Models\Donation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the Laboratory Supervisor that a reactive serology result is waiting
 * for their verification.
 *
 * Names the donation and its bag, which is how the bench finds the tube, and
 * who saved the result. Never the donor and never the marker: a notification
 * list is read on a shared screen, and both stay on the Testing page.
 *
 * In-app only, like LowStockAlert.
 */
class SerologyVerificationRequested extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Donation $donation,
        private readonly ?string $barcode,
        private readonly string $recordedBy
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
        $code = 'DON-'.str_pad((string) $this->donation->id, 6, '0', STR_PAD_LEFT);

        return [
            'category' => 'laboratory',
            'title' => 'A reactive serology result needs verifying',
            'desc' => $code.($this->barcode ? ' · Bag '.$this->barcode : '').', saved by '.$this->recordedBy
                .'. Nothing takes effect until you verify it against the bench record.',
            'tone' => 'warning',
            'icon' => 'flask-conical',
            'action_label' => 'Review',
            'action_route' => '/blood-center/serology-results?outcome=awaiting_verification',
            'donation_id' => $this->donation->id,
        ];
    }
}
