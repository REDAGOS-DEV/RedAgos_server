<?php

namespace Database\Factories;

use App\Enums\DonationStatus;
use App\Models\BloodCollection;
use App\Models\Donation;
use App\Models\DonorProfile;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    /**
     * Statuses a donation can only hold if a bag was actually drawn.
     *
     * @var array<int, DonationStatus>
     */
    private const DRAWN_STATUSES = [
        DonationStatus::Collected,
        DonationStatus::Tested,
        DonationStatus::Completed,
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'donor_id' => DonorProfile::factory(),
            'facility_id' => Facility::factory(),
            'appointment_id' => null,
            'donation_date' => now()->subMonths(4),
            'status' => DonationStatus::Completed,
            'volume_ml' => 450,
        ];
    }

    /**
     * Give every drawn donation the collection row the real workflow would have written.
     *
     * A donation cannot be `collected`, `tested` or `completed` without a bag
     * having come out of someone's arm, and `blood_collections` is the record of
     * that. The donation interval is keyed on this row, so a factory that
     * skipped it would build donations that never happened and let a donor who
     * gave blood yesterday book again today.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Donation $donation): void {
            if (! in_array($donation->status, self::DRAWN_STATUSES, true)) {
                return;
            }

            if ($donation->collection()->exists()) {
                return;
            }

            BloodCollection::create([
                'donation_id' => $donation->id,
                'collected_by' => User::factory()->create()->id,
                'collection_datetime' => $donation->donation_date,
            ]);
        });
    }

    /**
     * Indicate that the donation was completed on a given date.
     */
    public function completedAt(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DonationStatus::Completed,
            'donation_date' => $date,
        ]);
    }

    /**
     * Indicate that the donor was turned away before anything was drawn.
     *
     * No collection row follows, so this donation correctly does not count
     * towards the donor's 56-day interval.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DonationStatus::Rejected,
            'volume_ml' => null,
        ]);
    }

    /**
     * Indicate that the bag was drawn and then rejected, most often for a reactive result.
     *
     * The draw was real, so `configure()` writes the collection row and the
     * donor's interval still runs from it even though the blood never reached
     * a patient.
     */
    public function rejectedAfterCollection(?string $date = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DonationStatus::Rejected,
            'donation_date' => $date ?? $attributes['donation_date'],
        ])->afterCreating(function (Donation $donation): void {
            if ($donation->collection()->exists()) {
                return;
            }

            BloodCollection::create([
                'donation_id' => $donation->id,
                'collected_by' => User::factory()->create()->id,
                'collection_datetime' => $donation->donation_date,
            ]);
        });
    }
}
