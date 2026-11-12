<?php

namespace Tests\Feature\BloodCenter;

use App\Enums\StaffRole;
use App\Models\Donation;
use App\Models\DonationAppointment;
use App\Models\Facility;
use App\Models\MobileEvent;
use App\Models\User;
use App\Notifications\AppointmentScheduled;
use App\Notifications\DonorDeferred;
use App\Notifications\DriveCancelled;
use App\Notifications\DriveCancelledForRegistrant;
use App\Support\SmsName;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A household's shared mobile number (project owner, 2026-11-12).
 *
 * In remote areas one phone serves the whole family. Staff mark a donor's
 * number Personal or Shared: a Shared number may repeat, a Personal one is on
 * one account only, and someone else's Personal number is asked about first.
 * The donor portal only ever saves a number of the donor's own.
 */
class SharedDonorPhoneTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PHONE = '+639171234567';

    private Facility $facility;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->facility = Facility::factory()->approved()->create();
        $this->receptionist = User::factory()->bloodCenterStaff($this->facility, StaffRole::MedicalReceptionist)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function walkIn(string $first, string $idNumber, array $overrides = []): array
    {
        return [
            'first_name' => $first,
            'last_name' => 'Dela Cruz',
            'valid_id_number' => $idNumber,
            'birth_date' => now()->subYears(30)->toDateString(),
            'phone' => '0917 123 4567',
            ...$overrides,
        ];
    }

    private function register(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->receptionist)->postJson('/api/blood-center/donors', $payload);
    }

    // --- Staff: Personal or Shared ----------------------------------------------

    public function test_a_household_registers_several_donors_on_one_shared_number(): void
    {
        $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))
            ->assertCreated()
            ->assertJsonPath('data.phone', self::PHONE)
            ->assertJsonPath('data.phone_shared', true);

        $this->register($this->walkIn('Jose', 'N01-22-222222', ['phone_shared' => true]))
            ->assertCreated()
            ->assertJsonPath('data.phone_shared', true);

        $this->assertSame(2, User::where('phone', self::PHONE)->count());
    }

    public function test_a_personal_number_already_on_file_is_flagged_under_the_phone(): void
    {
        $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))->assertCreated();

        $this->register($this->walkIn('Jose', 'N01-22-222222'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This number is already on file. Choose Shared if the household shares this phone.']);
    }

    public function test_personal_is_the_default_and_a_second_personal_is_flagged(): void
    {
        $this->register($this->walkIn('Maria', 'N01-11-111111'))
            ->assertCreated()
            ->assertJsonPath('data.phone_shared', false);

        $this->register($this->walkIn('Jose', 'N01-22-222222', ['phone_shared' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_someone_elses_personal_number_is_asked_about_then_saved_as_shared(): void
    {
        // The son signed up in the portal with the family's only phone.
        User::factory()->donor()->create(['first_name' => 'Paolo', 'phone' => self::PHONE]);

        $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'phone_personal_elsewhere')
            ->assertJsonPath('message', 'This number is someone else\'s personal number. Save it as Shared anyway?');

        $this->assertSame(1, User::where('phone', self::PHONE)->count());

        $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true, 'confirm_shared_phone' => true]))
            ->assertCreated()
            ->assertJsonPath('data.phone_shared', true);

        $this->assertSame(2, User::where('phone', self::PHONE)->count());
    }

    public function test_a_staff_accounts_number_is_flagged_as_personal_too(): void
    {
        $this->receptionist->forceFill(['phone' => self::PHONE])->save();

        $this->register($this->walkIn('Maria', 'N01-11-111111'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'phone_personal_elsewhere');
    }

    public function test_the_paper_records_form_follows_the_same_rules(): void
    {
        $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))->assertCreated();
        $typist = User::factory()->bloodCenterStaff($this->facility, StaffRole::Phlebotomist)->create();

        $this->actingAs($typist)
            ->postJson('/api/blood-center/paper-sheets/donors', $this->walkIn('Jose', 'N01-22-222222'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->actingAs($typist)
            ->postJson('/api/blood-center/paper-sheets/donors', $this->walkIn('Jose', 'N01-22-222222', ['phone_shared' => true]))
            ->assertCreated();
    }

    public function test_staff_cannot_mark_a_number_personal_while_relatives_share_it(): void
    {
        $maria = $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))->json('data.uuid');
        $this->register($this->walkIn('Jose', 'N01-22-222222', ['phone_shared' => true]))->assertCreated();

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$maria}", ['phone_shared' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        // Her own new number can be Personal.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$maria}", ['phone' => '09181112222', 'phone_shared' => false])
            ->assertOk()
            ->assertJsonPath('data.phone', '+639181112222')
            ->assertJsonPath('data.phone_shared', false);
    }

    public function test_an_edit_to_a_shared_number_held_personally_elsewhere_is_asked_about(): void
    {
        $maria = $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone' => '09181112222']))->json('data.uuid');
        User::factory()->donor()->create(['phone' => self::PHONE]);

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$maria}", ['phone' => self::PHONE, 'phone_shared' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'phone_personal_elsewhere');

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$maria}", ['phone' => self::PHONE, 'phone_shared' => true, 'confirm_shared_phone' => true])
            ->assertOk()
            ->assertJsonPath('data.phone_shared', true);
    }

    public function test_an_untouched_shared_number_is_not_checked_again(): void
    {
        $maria = $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))->json('data.uuid');
        $this->register($this->walkIn('Jose', 'N01-22-222222', ['phone_shared' => true]))->assertCreated();

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$maria}", ['phone' => self::PHONE, 'phone_shared' => true, 'occupation' => 'Farmer'])
            ->assertOk()
            ->assertJsonPath('data.occupation', 'Farmer');
    }

    public function test_clearing_the_number_clears_shared(): void
    {
        $maria = $this->register($this->walkIn('Maria', 'N01-11-111111', ['phone_shared' => true]))->json('data.uuid');

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$maria}", ['phone' => null])
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.phone_shared', false);
    }

    public function test_shared_is_locked_with_the_phone_for_a_donor_who_keeps_their_own_contact(): void
    {
        $portal = User::factory()->donor()->create(['phone' => self::PHONE]);

        $this->actingAs($this->receptionist)
            ->patchJson("/api/blood-center/donors/{$portal->uuid}", ['phone_shared' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'The donor manages this from their own account.']);
    }

    public function test_the_database_holds_a_personal_number_to_one_account(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        User::factory()->create(['phone' => self::PHONE, 'phone_shared' => true]);
        User::factory()->create(['phone' => self::PHONE, 'phone_shared' => true]);

        $this->expectException(QueryException::class);

        User::factory()->create(['phone' => self::PHONE]);
    }

    // --- The portal: the donor's own number -------------------------------------

    public function test_portal_sign_up_still_refuses_a_number_already_on_file(): void
    {
        User::factory()->donor()->create(['phone' => self::PHONE, 'phone_shared' => true]);

        $this->postJson('/api/donors/register', [
            'first_name' => 'Paolo',
            'last_name' => 'Dela Cruz',
            'email' => 'paolo@example.com',
            'phone' => '09171234567',
            'gender' => 'male',
            'birth_date' => '1995-05-20',
            'address' => '123 Rizal Street, Davao City',
            'valid_id_type' => 'philsys',
            'valid_id_number' => '1234567890123456',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms_accepted' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This phone number is already registered.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(User $donor, array $overrides = []): array
    {
        $profile = $donor->donorProfile;

        return [
            'first_name' => $donor->first_name,
            'last_name' => $donor->last_name,
            'email' => $donor->email,
            'phone' => $donor->phone,
            'birth_date' => $profile->birth_date->format('Y-m-d'),
            'blood_type' => $profile->bloodType->code,
            'address' => $profile->address,
            ...$overrides,
        ];
    }

    public function test_a_donor_on_a_shared_number_still_saves_their_profile(): void
    {
        $maria = User::factory()->donor()->create(['phone' => self::PHONE, 'phone_shared' => true]);
        User::factory()->donor()->create(['phone' => self::PHONE, 'phone_shared' => true]);

        $this->actingAs($maria)
            ->patchJson('/api/donors/profile', $this->profilePayload($maria, ['address' => 'Purok 3, Barangay San Isidro']))
            ->assertOk();

        $this->assertTrue($maria->fresh()->phone_shared);
    }

    public function test_a_new_number_from_the_portal_is_the_donors_own(): void
    {
        $maria = User::factory()->donor()->create(['phone' => self::PHONE, 'phone_shared' => true]);
        User::factory()->donor()->create(['phone' => '+639181112222', 'phone_shared' => true]);

        $this->actingAs($maria)
            ->patchJson('/api/donors/profile', $this->profilePayload($maria, ['phone' => '09181112222']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This phone number is already registered.']);

        $this->actingAs($maria)
            ->patchJson('/api/donors/profile', $this->profilePayload($maria, ['phone' => '09190001111']))
            ->assertOk();

        $maria->refresh();
        $this->assertSame('+639190001111', $maria->phone);
        $this->assertFalse($maria->phone_shared);
    }

    // --- Finding the household ----------------------------------------------------

    public function test_the_exact_lookup_finds_everyone_on_a_shared_number(): void
    {
        $maria = User::factory()->donor()->create(['first_name' => 'Maria', 'phone' => self::PHONE, 'phone_shared' => true]);
        $jose = User::factory()->donor()->create(['first_name' => 'Jose', 'phone' => self::PHONE, 'phone_shared' => true]);
        User::factory()->donor()->create(['phone' => '+639181112222']);

        $rows = $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/donors/lookup/matches?type=phone&value='.urlencode(self::PHONE))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.phone_shared', true)
            ->json('data');

        $this->assertEqualsCanonicalizing([$maria->uuid, $jose->uuid], array_column($rows, 'uuid'));

        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/donors/lookup/matches?type=donor_code&value=DONOR-'.str_pad((string) $maria->id, 6, '0', STR_PAD_LEFT))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $maria->uuid);

        $this->actingAs($this->receptionist)
            ->getJson('/api/blood-center/donors/lookup/matches?type=phone&value=%2B639190009999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_counter_search_offers_everyone_on_a_shared_number(): void
    {
        User::factory()->donor()->create(['first_name' => 'Maria', 'phone' => self::PHONE, 'phone_shared' => true]);
        User::factory()->donor()->create(['first_name' => 'Jose', 'phone' => self::PHONE, 'phone_shared' => true]);

        $this->actingAs($this->receptionist)
            ->postJson('/api/blood-center/collection/donor-search', ['q' => '09171234567'])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.matched_by', 'phone')
            ->assertJsonPath('data.1.matched_by', 'phone');
    }

    // --- Texts say whose they are ---------------------------------------------------

    public function test_every_donor_text_opens_with_the_first_name(): void
    {
        $donor = User::factory()->donor()->create(['first_name' => 'Niño']);
        $facility = Facility::factory()->make(['name' => 'Southern Blood Centre']);
        $donation = Donation::factory()->make(['donation_date' => Carbon::parse('2026-11-03 09:00')])->setRelation('facility', $facility);
        $appointment = DonationAppointment::factory()->make(['appointment_datetime' => Carbon::parse('2026-11-10 09:30')])
            ->setRelation('facility', $facility)
            ->setRelation('mobileEvent', null);
        $drive = MobileEvent::factory()->make(['name' => 'Ateneo Drive', 'event_date' => Carbon::parse('2026-11-12')])->setRelation('facility', $facility);

        $this->assertStringStartsWith('RedAgos: Nino, your appointment is booked for 10 Nov 2026', (new AppointmentScheduled($appointment))->toSms($donor));
        $this->assertStringStartsWith('RedAgos: Nino, you were not able to donate at', (new DonorDeferred($donation, 'R2-Low'))->toSms($donor));
        $this->assertStringStartsWith('RedAgos: Nino, Southern Blood Centre has cancelled Ateneo Drive', (new DriveCancelled($drive))->toSms($donor));
        $this->assertStringStartsWith('RedAgos: Ana, Southern Blood Centre has cancelled', (new DriveCancelledForRegistrant($drive, 'Ana'))->toSms($donor));
        $this->assertStringStartsWith('RedAgos: Southern Blood Centre has cancelled', (new DriveCancelledForRegistrant($drive))->toSms($donor));
    }

    public function test_a_name_is_written_in_plain_letters(): void
    {
        $this->assertSame('Nino', SmsName::of('Niño'));
        $this->assertSame('Ma Teresa', SmsName::of('  Ma   Teresa '));
        $this->assertSame('', SmsName::of(null));
        $this->assertSame('RedAgos: Your appointment', SmsName::open(null, 'your appointment'));
    }
}
