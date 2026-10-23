<?php

namespace App\Service;

use App\Enums\AccountStatus;
use App\Enums\FacilityDonorStatus;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\BloodDriveScheduled;
use App\Repository\DonorDirectoryRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tell a centre's donors about a drive it just scheduled.
 *
 * "A centre's donors" is the Donors page's list (DonorDirectoryRepository):
 * anyone who booked, donated or was registered there, less those the centre
 * marked inactive and closed accounts. Without donor locations on file this
 * stands in for "nearby": the centres a donor uses are the ones they can reach.
 *
 * Each donor's own switches decide the channels (BloodDriveScheduled::via), so
 * a donor who turned both off is skipped there. Called after the drive is
 * saved; a mailer error is logged, never a reason to fail the scheduling.
 */
class BloodDriveAnnouncer
{
    private const CHUNK = 200;

    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository
    ) {}

    /**
     * @return int how many donors were sent the notice
     */
    public function announce(MobileEvent $drive): int
    {
        if ($drive->event_date->isBefore(Carbon::today())) {
            return 0;
        }

        $drive->loadMissing('facility');
        $sent = 0;

        $this->donorDirectoryRepository
            ->facilityDonorQuery($drive->facility_id, ['status' => FacilityDonorStatus::Active->value])
            ->reorder()
            ->chunkById(self::CHUNK, function ($donors) use ($drive, &$sent): void {
                $recipients = $donors->filter(fn (User $donor): bool => ! in_array(
                    $donor->account_status,
                    [AccountStatus::Suspended, AccountStatus::Deactivated],
                    true
                ));

                try {
                    Notification::send($recipients, new BloodDriveScheduled($drive));
                    $sent += $recipients->count();
                } catch (Throwable $exception) {
                    Log::warning('Could not send a blood drive notice.', [
                        'mobile_event_id' => $drive->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }, 'users.id', 'id');

        return $sent;
    }
}
