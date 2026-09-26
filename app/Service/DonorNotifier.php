<?php

namespace App\Service;

use App\Models\Donation;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell a donor what was recorded about their donation, without ever failing the request.
 *
 * Shared by the counter and the laboratory. Always called after the commit:
 * the bag is already drawn, the donor already sent home or the result already
 * recorded, so a mailer error is a message to chase up rather than a reason to
 * refuse a request that has succeeded.
 */
class DonorNotifier
{
    /**
     * @param  callable(User): Notification  $build
     */
    public function send(Donation $donation, callable $build, string $what): void
    {
        $donor = $donation->donorProfile?->donor;

        if ($donor === null) {
            return;
        }

        try {
            $donor->notify($build($donor));
        } catch (Throwable $exception) {
            Log::warning("Could not send the {$what}.", [
                'donor_id' => $donor->id,
                'donation_id' => $donation->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
