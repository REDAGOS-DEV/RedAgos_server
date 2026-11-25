<?php

namespace Tests\Feature\HospitalInventory;

use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\HospitalUnit;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\DonatedBloodTransfused;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\HospitalInventory\Concerns\BuildsHospitalStock;
use Tests\TestCase;

/**
 * The donor hears when blood they gave is given to a patient (project owner,
 * 2026-11-26): once per donation, on the first of its bags a hospital
 * transfuses, by email and text under their own Settings switch, and never
 * with anything about the patient.
 */
class DonorTransfusionNoticeTest extends TestCase
{
    use BuildsHospitalStock, LazilyRefreshDatabase;

    private User $donor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->donor = User::query()->findOrFail($this->donorProfile->donor_id);
        $this->donor->forceFill(['first_name' => 'Ana', 'phone' => '+639171234567'])->save();

        config([
            'services.semaphore.api_key' => 'sk-test',
            'services.semaphore.log_only' => false,
        ]);

        Notification::fake();
    }

    private function transfuse(HospitalUnit $unit): void
    {
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();
        $this->act($unit, 'transfuse')->assertOk();
    }

    private function donationOf(HospitalUnit $unit): Donation
    {
        return Donation::query()->findOrFail($unit->bloodUnit->donation_id);
    }

    public function test_the_first_transfusion_tells_the_donor_in_the_portal_by_email_and_by_text(): void
    {
        $unit = $this->receiveOne();

        $this->transfuse($unit);

        Notification::assertSentTo(
            $this->donor,
            DonatedBloodTransfused::class,
            fn (DonatedBloodTransfused $notice, array $channels): bool => $channels === ['mail', SmsChannel::class, 'database']
        );
        $this->assertNotNull($this->donationOf($unit)->donor_told_transfused_at);
    }

    public function test_a_donation_split_into_several_bags_thanks_the_donor_once(): void
    {
        [$first, $second] = $this->receiveStock(2)->all();
        $this->assertSame($first->bloodUnit->donation_id, $second->bloodUnit->donation_id);

        $this->transfuse($first);
        $this->transfuse($second);

        Notification::assertSentToTimes($this->donor, DonatedBloodTransfused::class, 1);
    }

    public function test_crossmatching_alone_tells_the_donor_nothing(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();
        $this->act($unit, 'crossmatch')->assertOk();

        Notification::assertNothingSentTo($this->donor);
        $this->assertNull($this->donationOf($unit)->donor_told_transfused_at);
    }

    public function test_a_refused_transfusion_claims_nothing(): void
    {
        $unit = $this->receiveOne();
        $this->tagUnit($unit)->assertOk();

        $this->act($unit, 'transfuse')->assertStatus(409);

        Notification::assertNothingSentTo($this->donor);
        $this->assertNull($this->donationOf($unit)->donor_told_transfused_at);
    }

    public function test_with_the_switch_off_the_donor_still_sees_it_in_the_portal(): void
    {
        DonorProfile::query()->where('donor_id', $this->donor->id)
            ->update(['notification_preferences' => ['transfusion_email' => false]]);
        $unit = $this->receiveOne();

        $this->transfuse($unit);

        Notification::assertSentTo(
            $this->donor,
            DonatedBloodTransfused::class,
            fn (DonatedBloodTransfused $notice, array $channels): bool => $channels === ['database']
        );
    }

    public function test_the_notice_says_nothing_about_the_patient_or_the_hospital(): void
    {
        $unit = $this->receiveOne();
        $this->transfuse($unit);

        $donation = $this->donationOf($unit);
        $notice = new DonatedBloodTransfused($donation->load('facility'));
        $date = $donation->donation_date->format('j M Y');

        $text = $notice->toSms($this->donor);
        $this->assertSame("RedAgos: Ana, blood you donated on {$date} has been given to a patient who needed it. Thank you for giving!", $text);
        $this->assertLessThanOrEqual(160, strlen($text));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $text);

        $inApp = json_encode($notice->toDatabase($this->donor));
        $mail = json_encode($notice->toMail($this->donor)->toArray());

        foreach ([$text, $inApp, $mail] as $copy) {
            $this->assertStringNotContainsString('Southern Hospital', $copy);
            $this->assertStringNotContainsString('Santos', $copy);
            $this->assertStringNotContainsString('Maria', $copy);
            $this->assertStringNotContainsString('HRN-000123', $copy);
        }
    }
}
