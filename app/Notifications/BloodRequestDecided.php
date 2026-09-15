<?php

namespace App\Notifications;

use App\Models\BloodRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a requesting blood bank what became of a request they raised.
 *
 * One class covers every outcome rather than four near-identical ones, because
 * the recipient, the channel and the payload shape are the same in each case
 * and only the wording differs.
 */
class BloodRequestDecided extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BloodRequest $request,
        private readonly string $outcome
    ) {}

    /**
     * Stored in-app only.
     *
     * No mail: MAIL_* is stock across environments, so a mail channel here
     * would queue messages nothing delivers. docs/CAPSTONE_CONTEXT.md records
     * database-now, email-later as the accepted position.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the in-app copy the requester's notifications screen lists.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => 'request',
            'title' => $this->title(),
            'desc' => $this->description(),
            'meta' => $this->request->reference_number,
            'tone' => $this->tone(),
            'action_label' => 'View request',
            'action_route' => '/hospital/bloodrequests/'.$this->request->id,
            'request_id' => $this->request->id,
            'reference_number' => $this->request->reference_number,
            'status' => $this->request->status->value,
            'outcome' => $this->outcome,
        ];
    }

    private function title(): string
    {
        return match ($this->outcome) {
            'allocated' => 'Blood request approved',
            'rejected' => 'Blood request rejected',
            'released' => 'Blood units dispatched',
            default => 'Blood request updated',
        };
    }

    private function description(): string
    {
        $facility = $this->request->targetFacility?->name ?? 'The fulfilling facility';
        $reference = $this->request->reference_number;

        return match ($this->outcome) {
            'allocated' => "{$facility} has reserved stock for request {$reference}.",
            'rejected' => "{$facility} could not fulfil request {$reference}. "
                .($this->request->rejection_reason ?? 'No reason was recorded.'),
            'released' => "{$facility} has dispatched the units for request {$reference}. "
                .'Confirm receipt once they arrive.',
            default => "Request {$reference} has been updated.",
        };
    }

    private function tone(): string
    {
        return match ($this->outcome) {
            'rejected' => 'warning',
            default => 'success',
        };
    }
}
