<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\PaymentAttemptStatus;
use App\Models\Facility;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\BuildsBillingFixtures;
use Tests\Concerns\FakesXendit;
use Tests\TestCase;

/**
 * The operator and scheduler commands behind GCash checkout.
 */
class PaymentCommandsTest extends TestCase
{
    use BuildsBillingFixtures, FakesXendit, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBillingFixtures();
        $this->priceComponent(500);
    }

    public function test_a_blood_centre_is_linked_to_and_unlinked_from_its_sub_account(): void
    {
        $this->artisan('facility:set-xendit-account', ['facility' => $this->centre->id, 'account' => 'sub-123'])
            ->assertSuccessful();

        $this->assertSame('sub-123', $this->centre->refresh()->xendit_sub_account_id);

        $this->artisan('facility:set-xendit-account', ['facility' => $this->centre->id, '--clear' => true])
            ->assertSuccessful();

        $this->assertNull($this->centre->refresh()->xendit_sub_account_id);
    }

    public function test_only_a_blood_centre_collects_payments(): void
    {
        $this->artisan('facility:set-xendit-account', ['facility' => $this->hospital->id, 'account' => 'sub-hosp'])
            ->assertFailed();

        $this->assertNull($this->hospital->refresh()->xendit_sub_account_id);
    }

    public function test_the_word_for_the_master_account_cannot_be_linked_as_a_sub_account(): void
    {
        $this->artisan('facility:set-xendit-account', ['facility' => $this->centre->id, 'account' => 'main'])
            ->assertFailed();

        $this->assertNull($this->centre->refresh()->xendit_sub_account_id);
    }

    public function test_one_sub_account_belongs_to_one_centre(): void
    {
        $other = Facility::factory()->approved()->create();
        $this->artisan('facility:set-xendit-account', ['facility' => $other->id, 'account' => 'sub-shared'])->assertSuccessful();

        $this->artisan('facility:set-xendit-account', ['facility' => $this->centre->id, 'account' => 'sub-shared'])
            ->assertFailed();
    }

    public function test_event_records_are_kept_until_a_retention_is_configured(): void
    {
        $old = PaymentProviderEvent::query()->create([
            'body_hash' => str_repeat('a', 64),
            'token_valid' => false,
            'outcome' => 'rejected',
            'received_at' => now()->subDays(400),
        ]);

        $this->artisan('payments:purge-events')->assertSuccessful();
        $this->assertModelExists($old);

        config(['services.xendit.event_retention_days' => 365]);
        $recent = PaymentProviderEvent::query()->create([
            'body_hash' => str_repeat('b', 64),
            'token_valid' => false,
            'outcome' => 'rejected',
            'received_at' => now()->subDays(10),
        ]);

        $this->artisan('payments:purge-events')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_reconciliation_finds_a_payment_whose_webhook_never_arrived(): void
    {
        $this->fakeXendit();
        $this->linkCentreToXendit();
        $request = $this->allocatedRequest(1);
        $attempt = $this->openCheckout($request);

        // No webhook at all; Xendit has the payment.
        $this->sessionStatus = 'COMPLETED';
        $this->travel(6)->minutes();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentAttemptStatus::Completed, $attempt->refresh()->status);
    }

    public function test_reconciliation_flags_a_checkout_whose_provider_call_never_finished(): void
    {
        $this->fakeXendit();
        $this->linkCentreToXendit();
        $request = $this->allocatedRequest(1);
        $this->openCheckout($request);

        $stranded = PaymentAttempt::query()->sole();
        $stranded->forceFill([
            'status' => PaymentAttemptStatus::Creating,
            'provider_session_id' => null,
        ])->save();

        $this->travel(11)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();

        $stranded->refresh();
        $this->assertSame(PaymentAttemptStatus::Failed, $stranded->status);
        $this->assertSame('session_unknown', $stranded->review_reason);
    }
}
