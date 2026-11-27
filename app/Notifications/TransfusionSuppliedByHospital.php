<?php

namespace App\Notifications;

use App\Models\BloodRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a hospital blood bank that another hospital sent bags for one of its Blood Requests.
 *
 * A hospital may supply another hospital's Blood Request from its own
 * near-expiry stock (project owner, 2026-11-28). The bags are on their way
 * at once, so the requesting hospital is told to receive them, as it would a
 * centre's dispatch.
 */
class TransfusionSuppliedByHospital extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BloodRequest $share
    ) {}

    /**
     * Stored in-app only, like every other request notification.
     *
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
        $supplier = $this->share->targetFacility?->name ?? 'Another hospital';
        $requirement = $this->share->transfusionRequest;
        $reference = $requirement?->reference_number ?? $this->share->reference_number;
        $units = (int) $this->share->quantity;

        return [
            'category' => 'request',
            'title' => 'Another hospital sent blood',
            'desc' => "{$supplier} sent {$units} bag".($units === 1 ? '' : 's')
                ." for Blood Request {$reference} as {$this->share->reference_number}. Receive them when they arrive.",
            'meta' => $reference,
            'tone' => 'success',
            'action_label' => 'View request',
            'action_route' => $requirement
                ? '/hospital/transfusion-requests/'.$requirement->id
                : '/hospital/bloodrequests/'.$this->share->id,
            'request_id' => $this->share->id,
            'reference_number' => $this->share->reference_number,
            'transfusion_request_id' => $requirement?->id,
            'transfusion_reference_number' => $requirement?->reference_number,
            'request_source' => $this->share->request_source->value,
        ];
    }
}
