<?php

namespace App\Notifications;

use App\Models\BloodRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a hospital blood bank that a blood centre recorded a request on its behalf.
 *
 * A watcher brought the patient's request to the centre, the centre phoned the
 * hospital, and the hospital confirmed it. This is the hospital's written
 * record of that: the request now sits in its own list and can be tracked and
 * received like any other.
 */
class WalkInRequestRecorded extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BloodRequest $request
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
        $centre = $this->request->targetFacility?->name ?? 'A blood centre';
        $patient = $this->request->patientFullName();
        $requirement = $this->request->transfusionRequest;
        $reference = $requirement?->reference_number ?? $this->request->reference_number;

        return [
            'category' => 'request',
            'title' => 'Walk-in request recorded on your behalf',
            'desc' => "{$centre} recorded walk-in request {$reference}"
                .($patient ? " for {$patient}" : '')
                .' after your blood bank confirmed it by phone.',
            'meta' => $reference,
            'tone' => 'info',
            'action_label' => 'View request',
            // The patient's requirement, where every centre's share of it is
            // followed together.
            'action_route' => $requirement
                ? '/hospital/transfusion-requests/'.$requirement->id
                : '/hospital/bloodrequests/'.$this->request->id,
            'request_id' => $this->request->id,
            'reference_number' => $this->request->reference_number,
            'transfusion_request_id' => $requirement?->id,
            'transfusion_reference_number' => $requirement?->reference_number,
            'request_source' => $this->request->request_source->value,
        ];
    }
}
