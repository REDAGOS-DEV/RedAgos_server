<?php

namespace App\Service;

use App\Enums\LineClosureReason;
use App\Enums\RequestEventType;
use App\Models\BloodRequest;
use App\Models\BloodRequestItem;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Closing the rest of a request line that will not be supplied here.
 *
 * Both sides may close a line, each for its own reason: the fulfilling centre
 * when it has none to give, the requesting hospital when it no longer needs
 * the rest. The requested quantity is never touched — the line still says what
 * was asked for — and units already reserved on it are left alone: those are
 * still to be released or returned, so a line with reserved units is not
 * finished until they are.
 *
 * Callers resolve and lock the request themselves, scoped to their own side of
 * the exchange, and pass it in.
 */
class RequestLineCloser
{
    public function __construct(
        private readonly RequestStatusResolver $resolver,
        private readonly BloodRequestHistory $history,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Close one line of a locked request and settle the request.
     */
    public function close(
        BloodRequest $request,
        int $itemId,
        LineClosureReason $reason,
        ?string $note,
        User $user
    ): BloodRequest {
        if ($request->isClosed()) {
            throw $this->refuse(
                409,
                'request_closed',
                "This request is {$request->status->label()} and can no longer be changed."
            );
        }

        /** @var BloodRequestItem|null $item */
        $item = $request->items()->whereKey($itemId)->first();

        if ($item === null) {
            throw $this->refuse(404, 'request_item_not_found', 'That component is not on this request.');
        }

        if ($item->closed_at !== null) {
            throw $this->refuse(409, 'line_already_closed', 'The rest of this component has already been closed.');
        }

        $lines = $this->resolver->freshFigures($request);
        $line = $lines->get($item->id);

        if (($line['allocatable'] ?? 0) < 1) {
            throw $this->refuse(
                409,
                'nothing_to_close',
                'Every unit of this component is already supplied, reserved or forwarded to another facility.'
            );
        }

        if ($this->resolver->wouldEmpty($lines, closing: [$item->id])) {
            throw $this->refuse(
                409,
                'close_would_empty',
                'Nothing has been supplied on this request yet, so closing this would leave it with nothing. '
                .'Reject or cancel the request instead.'
            );
        }

        $from = $request->status;

        $item->closed_at = now();
        $item->closed_by = $user->id;
        $item->closure_reason = $reason;
        $item->closure_note = $note;
        $item->save();

        $this->resolver->settle($request);

        $this->history->record(
            $request,
            RequestEventType::LineClosed,
            $user,
            $from,
            trim($reason->label().($note ? " — {$note}" : '')),
            item: $item,
            meta: ['closure_reason' => $reason->value, 'closed_quantity' => $line['allocatable']],
        );

        $this->auditLogger->record($user, 'request.line_closed', $request, [
            'facility_id' => $user->facility_id,
            'reference_number' => $request->reference_number,
            'request_item_id' => $item->id,
            'closure_reason' => $reason->value,
            'closed_quantity' => $line['allocatable'],
            'status' => $request->status->value,
        ]);

        return $request;
    }

    /**
     * Build the project's standard refusal envelope.
     */
    private function refuse(int $status, string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
        ], $status));
    }
}
