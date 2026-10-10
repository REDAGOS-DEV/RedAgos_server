<?php

namespace Tests\Feature\BloodCenter;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The command existing is not the command running.
 *
 * This is the failure nothing else in the suite would notice: the sweep is
 * written, its own tests pass because they invoke it by hand, and no scheduler
 * ever calls it. Past-expiry units then keep reporting as available and the API
 * is confidently wrong about issuable stock — silently, for as long as it takes
 * someone to check the shelf against the screen.
 */
class ScheduleRegistrationTest extends TestCase
{
    public function test_the_expiry_sweep_is_registered_on_the_schedule(): void
    {
        $this->sweepEvent();
    }

    public function test_the_sweep_runs_daily_at_half_past_midnight(): void
    {
        // Far enough past the date boundary that a unit expiring "today" has had
        // its whole day, and off the hour every other cron on a shared box fires
        // at.
        $this->assertSame('30 0 * * *', $this->sweepEvent()->expression);
    }

    public function test_the_sweep_runs_on_the_operational_timezone(): void
    {
        // The same config value the command resolves its date through, so the
        // hour it runs at and the day it works out cannot drift apart.
        $this->assertSame(config('blood_center.timezone'), $this->sweepEvent()->timezone);
    }

    public function test_the_sweep_cannot_overlap_itself_or_run_twice_at_once(): void
    {
        $event = $this->sweepEvent();

        $this->assertTrue($event->withoutOverlapping, 'A slow sweep must not have a second one started on top of it.');
        $this->assertTrue($event->onOneServer, 'Two servers running the sweep would each write the audit trail for it.');
    }

    public function test_the_hospital_tag_sweep_runs_every_minute(): void
    {
        // A 24-hour tag that lapses must free its bag within the minute, not
        // overnight.
        $this->assertSame('* * * * *', $this->eventFor('hospital:expire-tags')->expression);
    }

    public function test_the_hospital_tag_sweep_cannot_overlap_and_recovers_from_a_crashed_run(): void
    {
        $event = $this->eventFor('hospital:expire-tags');

        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(10, $event->expiresAt, 'The default day-long overlap lock would stall untagging for a day after one crash.');
    }

    public function test_the_hospital_expiry_sweep_runs_with_the_centres_at_half_past_midnight(): void
    {
        $event = $this->eventFor('hospital:expire-units');

        $this->assertSame('30 0 * * *', $event->expression);
        $this->assertSame(config('blood_center.timezone'), $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_the_stock_threshold_check_runs_every_minute_and_recovers_from_a_crashed_run(): void
    {
        // A shortage is not a thing to learn about tomorrow, and the default
        // day-long overlap lock would silence alerts for a day after one crash.
        $event = $this->eventFor('inventory:check-thresholds');

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_payment_reconciliation_runs_every_minute_and_recovers_from_a_crashed_run(): void
    {
        // The second net under GCash webhooks: a watcher is waiting at the
        // counter, so a lost webhook must be found within minutes.
        $event = $this->eventFor('payments:reconcile');

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_webhook_record_retention_runs_daily(): void
    {
        $event = $this->eventFor('payments:purge-events');

        $this->assertSame('15 1 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    /**
     * The registered sweep, or a failed test saying it is missing.
     */
    private function sweepEvent(): Event
    {
        return $this->eventFor('inventory:expire-units');
    }

    /**
     * The registered event for a command, or a failed test saying it is missing.
     */
    private function eventFor(string $command): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

        $this->assertNotNull(
            $event,
            "{$command} is not registered in routes/console.php, so nothing will ever run it."
        );

        return $event;
    }
}
