<?php

namespace App\Notifications;

use App\Enums\HandoverMethod;
use App\Models\BloodRequest;
use Carbon\CarbonImmutable;
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

    /**
     * @param  array<string, mixed>  $context  closed_short: component, quantity, note; planned/replanned: the plan's summary
     */
    public function __construct(
        private readonly BloodRequest $request,
        private readonly string $outcome,
        private readonly array $context = []
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
            // A facility allocation is followed on the patient's requirement,
            // beside every other centre's share; receipt is confirmed from
            // there too.
            'action_route' => $this->request->transfusion_request_id !== null
                ? '/hospital/transfusion-requests/'.$this->request->transfusion_request_id
                : '/hospital/bloodrequests/'.$this->request->id,
            'request_id' => $this->request->id,
            'reference_number' => $this->request->reference_number,
            'transfusion_request_id' => $this->request->transfusion_request_id,
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
            'closed_short' => 'Part of a blood request cannot be supplied',
            'planned' => 'Replenishment order confirmed',
            'replanned' => 'Replenishment order rescheduled',
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
                .($this->request->rejection_reason ?? 'No reason was recorded.')
                .($this->request->transfusion_request_id !== null
                    ? ' Its units are unallocated again; ask another facility for them.'
                    : ''),
            'released' => "{$facility} has dispatched the units for request {$reference}. "
                .'Confirm receipt once they arrive.',
            'closed_short' => $this->closedShortDescription($facility, $reference),
            'planned' => "{$facility} confirmed order {$reference}: ".$this->planDescription(),
            'replanned' => "{$facility} changed how order {$reference} is supplied: ".$this->planDescription(),
            default => "Request {$reference} has been updated.",
        };
    }

    /**
     * What a centre's supply plan means for the hospital (project owner,
     * 2026-10-29): how many units, and when to collect them or expect them.
     */
    private function planDescription(): string
    {
        $committed = (int) ($this->context['committed'] ?? 0);
        $requested = (int) ($this->context['requested'] ?? 0);
        $method = HandoverMethod::tryFrom((string) ($this->context['handover_method'] ?? ''));
        $when = isset($this->context['scheduled_at'])
            ? CarbonImmutable::parse((string) $this->context['scheduled_at'])
                ->setTimezone((string) config('blood_center.timezone', 'Asia/Manila'))
                ->format('D j M, g:i A')
            : null;

        $units = "{$committed} of {$requested} unit(s)";
        $handover = match (true) {
            $when === null => '',
            $method === HandoverMethod::Pickup => ", ready for pick-up on {$when}",
            default => ", delivered on {$when}",
        };

        return "{$units}{$handover}.";
    }

    /**
     * A centre closed the rest of one component as unavailable (project owner,
     * 2026-10-19): what, how much, and that another facility can be asked.
     */
    private function closedShortDescription(string $facility, ?string $reference): string
    {
        $quantity = (int) ($this->context['quantity'] ?? 0);
        $component = $this->context['component'] ?? 'one component';
        $what = $quantity > 0 ? "{$quantity} unit(s) of {$component}" : "the rest of {$component}";
        $note = trim((string) ($this->context['note'] ?? ''));

        return "{$facility} cannot supply {$what} on request {$reference}."
            .($note !== '' ? " {$note}." : '')
            .($this->request->transfusion_request_id !== null
                ? ' Those units are unallocated again; ask another facility for them.'
                : ' Ask another facility for them.');
    }

    private function tone(): string
    {
        return match ($this->outcome) {
            'rejected', 'closed_short' => 'warning',
            default => 'success',
        };
    }
}
