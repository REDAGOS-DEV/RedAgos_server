<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Enums\BloodUnitStatus;
use App\Enums\FacilityTypeName;
use App\Enums\ReferralStatus;
use App\Models\BloodUnit;
use App\Models\CounsellingReferral;
use App\Models\DonationAppointment;
use App\Models\DonorDeferral;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\DeferralsEnding;
use App\Notifications\DriveTomorrow;
use App\Notifications\MorningBriefing;
use App\Notifications\ReferralsWaiting;
use App\Notifications\UnitsExpiringSoon;
use App\Support\StaffNotificationTopics;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Each blood centre's morning reminders, to whoever handles each:
 *
 *   - the day ahead (today's appointments and drives), to the appointment
 *     roles, on a day with something booked;
 *   - tomorrow's mobile drives, to whoever runs drives and the appointment roles;
 *   - temporary deferrals that end today, to whoever reads donors' clinical
 *     records. Only those ending today are counted, so each is told once;
 *   - available units that expire within a few days, to inventory viewers;
 *   - counselling referrals still pending a few days on, to whoever follows
 *     them up. Only the referrals that reach that age today are counted, so
 *     each is chased once rather than every morning.
 *
 * Recipients and their switches are StaffNotificationTopics', the list the
 * staff Settings screen shows. One centre's failure is logged and the rest
 * carry on.
 */
class SendStaffReminders extends Command
{
    protected $signature = 'blood-center:send-reminders';

    protected $description = 'Send blood centre staff their morning reminders (briefing, drives, deferrals ending, expiring units, waiting referrals)';

    public function handle(): int
    {
        $today = Carbon::today(config('blood_center.timezone'));
        $sent = 0;

        $centres = Facility::query()->with('facilityType')->get()->filter(
            fn (Facility $facility): bool => $facility->status->canOperate()
                && FacilityTypeName::tryFrom((string) $facility->facilityType?->name) === FacilityTypeName::BloodCenter
        );

        foreach ($centres as $centre) {
            try {
                $sent += $this->remind($centre, $today);
            } catch (Throwable $exception) {
                Log::warning('Staff reminders failed for a blood centre.', [
                    'facility_id' => $centre->id,
                    'exception' => $exception->getMessage(),
                ]);
                report($exception);
            }
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }

    private function remind(Facility $centre, Carbon $today): int
    {
        $staff = User::query()->where('facility_id', $centre->id)->get();
        $sent = 0;

        $to = fn (callable $rule): Collection => $staff->filter(fn (User $user): bool => $rule($user))->values();

        $appointments = DonationAppointment::query()
            ->where('facility_id', $centre->id)
            ->whereIn('status', AppointmentStatus::activeValues())
            ->whereDate('appointment_datetime', $today)
            ->count();
        $drivesToday = MobileEvent::query()->where('facility_id', $centre->id)->whereDate('event_date', $today)->pluck('name')->all();

        if ($appointments > 0 || $drivesToday !== []) {
            $recipients = $to([StaffNotificationTopics::class, 'receivesMorningBriefing']);
            Notification::send($recipients, new MorningBriefing($appointments, $drivesToday));
            $sent += $recipients->count();
        }

        $ending = DonorDeferral::query()
            ->where('facility_id', $centre->id)
            ->whereNull('lifted_at')
            ->whereDate('ends_on', $today)
            ->count();

        if ($ending > 0) {
            $recipients = $to([StaffNotificationTopics::class, 'receivesDeferralsEnding']);
            Notification::send($recipients, new DeferralsEnding($ending));
            $sent += $recipients->count();
        }

        foreach (MobileEvent::query()->where('facility_id', $centre->id)->whereDate('event_date', $today->copy()->addDay())->get() as $drive) {
            $recipients = $to([StaffNotificationTopics::class, 'receivesDriveReminders']);
            Notification::send($recipients, new DriveTomorrow($drive));
            $sent += $recipients->count();
        }

        $days = StaffNotificationTopics::EXPIRY_WARNING_DAYS;
        $expiring = BloodUnit::query()
            ->where('facility_id', $centre->id)
            ->where('status', BloodUnitStatus::Available->value)
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($days))
            ->count();

        if ($expiring > 0) {
            $recipients = $to([StaffNotificationTopics::class, 'receivesExpiringUnits']);
            Notification::send($recipients, new UnitsExpiringSoon($expiring, $days));
            $sent += $recipients->count();
        }

        $wait = StaffNotificationTopics::REFERRAL_WAIT_DAYS;
        $waiting = CounsellingReferral::query()
            ->where('facility_id', $centre->id)
            ->where('status', ReferralStatus::Pending->value)
            ->whereDate('created_at', $today->copy()->subDays($wait))
            ->count();

        if ($waiting > 0) {
            $recipients = $to([StaffNotificationTopics::class, 'receivesReferrals']);
            Notification::send($recipients, new ReferralsWaiting($waiting, $wait));
            $sent += $recipients->count();
        }

        return $sent;
    }
}
