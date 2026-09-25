<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\DonationAppointment;
use App\Models\DatabaseNotification;
use App\Notifications\ScreeningWindowOpen;
use App\Support\AppointmentScreeningWindow;
use App\Support\OperationalDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tell donors their health questionnaire is due.
 *
 * Booking no longer requires a questionnaire, so this is the only thing between
 * a donor booking and arriving with nothing to scan. It runs daily and covers
 * two stages: appointments tomorrow, whose window has just opened, and
 * appointments today, whose donor has still not answered.
 *
 * Idempotent per donor, per appointment, per stage. The cron may be re-run, a
 * deploy may overlap one, and neither should mail a donor twice -- so an
 * existing notification for the same appointment and stage is what stops a
 * second send, rather than the command assuming it runs exactly once.
 *
 * A donor with no questionnaire is never skipped for being "probably fine": the
 * whole point is that nothing else catches them.
 */
class OpenScreeningWindow extends Command
{
    protected $signature = 'donors:open-screening-window {--dry-run : List who would be notified without sending}';

    protected $description = 'Notify donors whose health questionnaire is due for an upcoming appointment';

    public function handle(): int
    {
        $today = OperationalDay::today();

        $sent = 0;
        $sent += $this->notifyStage($today->addDay()->toDateString(), 'opened');
        $sent += $this->notifyStage($today->toDateString(), 'final');

        $this->info($this->option('dry-run')
            ? "{$sent} donor(s) would be notified."
            : "{$sent} donor(s) notified.");

        return self::SUCCESS;
    }

    /**
     * Notify every donor booked on a date who has not answered in the window.
     */
    private function notifyStage(string $date, string $stage): int
    {
        $appointments = DonationAppointment::query()
            ->with(['donorProfile.donor', 'facility', 'mobileEvent'])
            ->whereIn('status', AppointmentStatus::activeValues())
            ->whereDate('appointment_datetime', $date)
            ->get();

        $sent = 0;

        foreach ($appointments as $appointment) {
            $donor = $appointment->donorProfile?->donor;

            if ($donor === null || $this->hasAnsweredInWindow($appointment)) {
                continue;
            }

            if ($this->alreadyTold($donor->id, $appointment->id, $stage)) {
                continue;
            }

            $this->line("  {$stage}: donor {$donor->id}, appointment {$appointment->id}");

            if ($this->option('dry-run')) {
                $sent++;

                continue;
            }

            // Never allowed to fail the run: one donor's bad address must not
            // stop everyone behind them in the list from being told.
            try {
                $donor->notify(new ScreeningWindowOpen($appointment, $stage === 'final'));
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('Could not send the screening window reminder.', [
                    'donor_id' => $donor->id,
                    'appointment_id' => $appointment->id,
                    'stage' => $stage,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * Determine whether the donor has already answered for this appointment.
     */
    private function hasAnsweredInWindow(DonationAppointment $appointment): bool
    {
        $opensAt = AppointmentScreeningWindow::opensAt($appointment);

        return DB::table('eligibility_screenings')
            ->where('donor_id', $appointment->donor_id)
            ->where('screened_at', '>=', $opensAt)
            ->exists();
    }

    /**
     * Determine whether this donor already holds a reminder for this stage.
     *
     * Reads the stored in-app copy, which every send writes regardless of
     * whether the mail channel was used, so it is a reliable record of having
     * told them even for a donor with no email address.
     */
    private function alreadyTold(int $donorId, int $appointmentId, string $stage): bool
    {
        return DB::table('notifications')
            ->where('notifiable_id', $donorId)
            ->where('type', ScreeningWindowOpen::class)
            ->where('data', 'like', '%"appointment_id":'.$appointmentId.'%')
            ->where('data', 'like', '%"stage":"'.$stage.'"%')
            ->exists();
    }
}
