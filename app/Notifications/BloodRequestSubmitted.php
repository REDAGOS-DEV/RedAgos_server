<?php

namespace App\Notifications;

use App\Models\BloodRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a fulfilling facility's staff that a request has arrived for them.
 *
 * The paper requires the receiving facility to be notified on submission; this
 * is that notification. It is sent to the inventory staff of the target
 * facility, because that is the department chartered to receive and process
 * incoming requests.
 */
class BloodRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BloodRequest $request
    ) {}

    /**
     * Stored in-app only, for the same reason as every other request notification.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the in-app copy the incoming-request screen lists.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $from = $this->request->requestingFacility?->name ?? 'A hospital blood bank';
        $urgency = $this->request->urgency_level->label();

        return [
            'category' => 'request',
            'title' => $this->request->urgency_level->isPrioritised()
                ? 'Emergency blood request received'
                : 'New blood request received',
            'desc' => "{$from} has requested {$this->request->quantity} unit(s) of "
                .($this->request->bloodType?->code ?? 'blood')
                .' '.($this->request->component?->name ?? '')
                .". Urgency: {$urgency}.",
            'meta' => $this->request->reference_number,
            'tone' => $this->request->urgency_level->isPrioritised() ? 'warning' : 'info',
            'action_label' => 'Review request',
            'action_route' => '/blood-center/bloodrequests',
            'request_id' => $this->request->id,
            'reference_number' => $this->request->reference_number,
            'urgency_level' => $this->request->urgency_level->value,
        ];
    }
}
