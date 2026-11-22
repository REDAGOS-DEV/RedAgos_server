<?php

namespace App\Notifications;

use App\Models\BloodRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a hospital blood bank that a blood centre took up part of one of its Blood Requests.
 *
 * Every centre can read every hospital's Blood Requests (project owner,
 * 2026-11-19), and one may add its own share of units nobody was asked for
 * yet. The hospital did not ask that centre, so it is told: the share now
 * sits under its Blood Request beside the others, and it may withdraw it
 * while the centre has not answered.
 */
class TransfusionShareTakenUp extends Notification
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
        $centre = $this->share->targetFacility?->name ?? 'A blood centre';
        $patient = $this->share->patientFullName();
        $requirement = $this->share->transfusionRequest;
        $reference = $requirement?->reference_number ?? $this->share->reference_number;
        $units = (int) $this->share->quantity;

        return [
            'category' => 'request',
            'title' => 'A blood centre added its share',
            'desc' => "{$centre} added {$units} unit".($units === 1 ? '' : 's')." to Blood Request {$reference}"
                .($patient ? " for {$patient}" : '')
                ." as {$this->share->reference_number}.",
            'meta' => $reference,
            'tone' => 'info',
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
