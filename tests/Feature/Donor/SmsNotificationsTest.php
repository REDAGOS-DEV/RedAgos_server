<?php

namespace Tests\Feature\Donor;

use App\Enums\ScreeningOutcome;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\AppointmentScheduled;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\DonationRecorded;
use App\Notifications\DonorDeferred;
use App\Notifications\DriveCancelled;
use App\Notifications\MailDeliveryCheck;
use App\Service\SemaphoreSms;
use App\Support\DonorNotificationPreferences;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Donor notices texted through Semaphore, and the two console checks for a
 * deployment's mail and SMS settings.
 */
class SmsNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function semaphoreOn(): void
    {
        config([
            'services.semaphore.api_key' => 'sk-test',
            'services.semaphore.sender_name' => 'REDAGOS',
            'services.semaphore.base_url' => 'https://api.semaphore.co/api/v4',
        ]);
    }

    private function fakeSemaphore(): void
    {
        Http::fake([
            'api.semaphore.co/api/v4/messages' => Http::response([[
                'message_id' => 1234,
                'recipient' => '639171234567',
                'status' => 'Pending',
            ]]),
            'api.semaphore.co/api/v4/account*' => Http::response([
                'account_name' => 'RedAgos', 'credit_balance' => 98,
            ]),
        ]);
    }

    private function donor(array $attributes = [], ?array $preferences = null): User
    {
        $donor = User::factory()->donor()->create($attributes);

        if ($preferences !== null) {
            DonorProfile::where('donor_id', $donor->id)->update(['notification_preferences' => $preferences]);
        }

        return $donor->fresh(['donorProfile']);
    }

    public function test_only_a_philippine_mobile_is_a_recipient(): void
    {
        $this->assertSame('639171234567', SemaphoreSms::recipient('+639171234567'));
        $this->assertSame('639171234567', SemaphoreSms::recipient('0917 123 4567'));
        $this->assertSame('639171234567', SemaphoreSms::recipient('639171234567'));
        $this->assertNull(SemaphoreSms::recipient('+63281234567'));
        $this->assertNull(SemaphoreSms::recipient('+6391712345'));
        $this->assertNull(SemaphoreSms::recipient(null));
    }

    public function test_no_text_while_semaphore_is_not_configured(): void
    {
        $channels = DonorNotificationPreferences::alwaysInAppChannels($this->donor(), DonorNotificationPreferences::RESULTS_EMAIL, sms: true);

        $this->assertSame(['mail', 'database'], $channels);
    }

    public function test_the_text_follows_the_email_switch(): void
    {
        $this->semaphoreOn();
        $channels = fn (User $donor): array => DonorNotificationPreferences::alwaysInAppChannels($donor, DonorNotificationPreferences::RESULTS_EMAIL, sms: true);

        $this->assertSame(['mail', SmsChannel::class, 'database'], $channels($this->donor()));
        $this->assertSame(['database'], $channels($this->donor(preferences: ['results_email' => false])));
    }

    public function test_a_donor_with_no_email_is_still_texted_and_a_landline_is_not(): void
    {
        $this->semaphoreOn();
        $channels = fn (User $donor): array => DonorNotificationPreferences::alwaysInAppChannels($donor, DonorNotificationPreferences::RESULTS_EMAIL, sms: true);

        $this->assertSame([SmsChannel::class, 'database'], $channels($this->donor(['email' => null])));
        $this->assertSame(['mail', 'database'], $channels($this->donor(['phone' => '+63281234567'])));
    }

    public function test_notices_without_sms_never_pick_it(): void
    {
        $this->semaphoreOn();

        $this->assertSame(['mail', 'database'], DonorNotificationPreferences::alwaysInAppChannels($this->donor(), DonorNotificationPreferences::RESULTS_EMAIL));
    }

    public function test_the_four_donor_notices_are_texted(): void
    {
        $this->semaphoreOn();
        $donor = $this->donor();
        $facility = Facility::factory()->make(['name' => 'Southern Blood Centre']);

        $donation = Donation::factory()->make(['donation_date' => Carbon::parse('2026-11-03 09:00')])->setRelation('facility', $facility);
        $appointment = DonationAppointment::factory()->make(['appointment_datetime' => Carbon::parse('2026-11-10 09:30')])
            ->setRelation('facility', $facility)
            ->setRelation('mobileEvent', null);
        $drive = MobileEvent::factory()->make(['name' => 'Ateneo Drive', 'event_date' => Carbon::parse('2026-11-12')])->setRelation('facility', $facility);

        $notices = [
            new AppointmentScheduled($appointment),
            new DonationRecorded($donation, Carbon::parse('2027-01-28')),
            new DonorDeferred($donation, 'R2-Low'),
            new DriveCancelled($drive),
        ];

        foreach ($notices as $notice) {
            $this->assertContains(SmsChannel::class, $notice->via($donor), $notice::class);
            $this->assertStringStartsWith('RedAgos: ', $notice->toSms($donor));
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $notice->toSms($donor), $notice::class.' must be plain ASCII');
        }

        $this->assertStringContainsString('10 Nov 2026, 9:30 AM', $notices[0]->toSms($donor));
        $this->assertStringContainsString('donate again from 28 Jan 2027', $notices[1]->toSms($donor));
        $this->assertStringContainsString('Deferral code: R2-Low.', $notices[2]->toSms($donor));
        $this->assertStringContainsString('cancelled Ateneo Drive on 12 Nov 2026', $notices[3]->toSms($donor));
    }

    public function test_a_blocking_deferral_text_offers_no_rebooking(): void
    {
        $donation = Donation::factory()->make(['donation_date' => Carbon::parse('2026-11-03')])
            ->setRelation('facility', Facility::factory()->make());
        $blocking = collect(ScreeningOutcome::cases())->first(fn (ScreeningOutcome $outcome): bool => $outcome->isBlocking());

        $text = (new DonorDeferred($donation, 'R1', $blocking))->toSms($this->donor());

        $this->assertStringContainsString('Please speak to our staff before booking again.', $text);
        $this->assertStringNotContainsString('book again after', $text);
    }

    public function test_the_channel_posts_the_text_to_semaphore(): void
    {
        $this->semaphoreOn();
        $this->fakeSemaphore();
        $donor = $this->donor(['phone' => '+639171234567']);
        $donation = Donation::factory()->make(['donation_date' => Carbon::parse('2026-11-03')])
            ->setRelation('facility', Facility::factory()->make(['name' => 'Southern Blood Centre']));

        app(SmsChannel::class)->send($donor, new DonationRecorded($donation));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.semaphore.co/api/v4/messages'
            && $request->isForm()
            && $request['apikey'] === 'sk-test'
            && $request['number'] === '639171234567'
            && $request['sendername'] === 'REDAGOS'
            && str_contains($request['message'], 'Southern Blood Centre'));
    }

    public function test_a_refused_message_fails_the_job(): void
    {
        $this->semaphoreOn();
        Http::fake(['api.semaphore.co/*' => Http::response(['sendername' => ['The selected sendername is invalid.']])]);

        $this->expectException(RuntimeException::class);

        app(SemaphoreSms::class)->send('09171234567', 'RedAgos: hello');
    }

    public function test_log_only_texts_go_to_the_log_and_never_to_semaphore(): void
    {
        config(['services.semaphore.log_only' => true]);
        Http::fake();
        Log::spy();
        $donor = $this->donor(['phone' => '+639171234567']);

        $this->assertSame(['mail', SmsChannel::class, 'database'], DonorNotificationPreferences::alwaysInAppChannels($donor, DonorNotificationPreferences::RESULTS_EMAIL, sms: true));

        $this->artisan('sms:test', ['number' => '09171234567'])
            ->expectsOutputToContain('Nothing was sent')
            ->assertSuccessful();

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'SEMAPHORE_LOG_ONLY')
            && $context['to'] === '639171234567');
    }

    public function test_sms_test_command_sends_and_reports_semaphores_answer(): void
    {
        $this->semaphoreOn();
        $this->fakeSemaphore();

        $this->artisan('sms:test', ['number' => '0917 123 4567'])
            ->expectsOutputToContain('98 credits')
            ->expectsOutputToContain('message 1234')
            ->assertSuccessful();
    }

    public function test_sms_test_command_refuses_what_would_not_be_sent(): void
    {
        $this->artisan('sms:test', ['number' => '09171234567'])
            ->expectsOutputToContain('SEMAPHORE_API_KEY')
            ->assertFailed();

        $this->semaphoreOn();
        Http::fake();

        $this->artisan('sms:test', ['number' => '+63281234567'])->assertFailed();
        $this->artisan('sms:test', ['number' => '09171234567', '--message' => 'Test from RedAgos'])
            ->expectsOutputToContain('starts with "TEST"')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_mail_test_command_sends_now_or_through_the_queue(): void
    {
        Notification::fake();

        $this->artisan('mail:test', ['address' => 'demo@example.com'])->assertSuccessful();
        $this->artisan('mail:test', ['address' => 'demo@example.com', '--queued' => true])
            ->expectsOutputToContain('queue worker')
            ->assertSuccessful();
        $this->artisan('mail:test', ['address' => 'not-an-address'])->assertFailed();

        Notification::assertSentOnDemandTimes(MailDeliveryCheck::class, 2);
        Notification::assertSentOnDemand(MailDeliveryCheck::class, fn (MailDeliveryCheck $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'demo@example.com');
    }
}
