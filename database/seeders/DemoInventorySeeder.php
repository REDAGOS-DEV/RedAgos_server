<?php

namespace Database\Seeders;

use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\MarkerResult;
use App\Enums\ScreeningOutcome;
use App\Enums\TestResult;
use App\Models\BloodCollection;
use App\Models\BloodComponent;
use App\Models\BloodType;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationClearance;
use App\Models\DonationComponent;
use App\Models\DonationImmunohematology;
use App\Models\DonationScreening;
use App\Models\DonationSerology;
use App\Models\DonationTestResult;
use App\Models\Facility;
use App\Models\FacilityBloodComponent;
use App\Models\User;
use App\Service\DonorService;
use App\Support\OperationalDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Available stock of every blood type, for a blood centre to work against:
 * 50 units of every component in every blood type (UNITS_PER_COMPONENT).
 *
 * DEMO DATA. Not called from DatabaseSeeder: run it on purpose with
 * `php artisan db:seed --class=DemoInventorySeeder`.
 *
 * Each unit is built the way the real workflow builds one — a donor, an
 * accepted screening, a recorded collection, both laboratory sections, both
 * clearances and the components Processing declared — because blood_units
 * requires a donation, and a unit with no clearances behind it is stock the
 * dispatch gate would rightly refuse to issue.
 *
 * Seeds every approved blood centre that has a staff member to attribute the
 * records to and a configured shelf life for at least one component. Shelf
 * life is never invented here: a component the facility has not configured is
 * left out, as intake itself would refuse it.
 *
 * Re-running is a no-op. Demo donors are keyed by email, and a donor who has
 * already donated at the facility is skipped.
 */
class DemoInventorySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * How many available units of each component every blood type gets.
     */
    public const UNITS_PER_COMPONENT = 50;

    /**
     * @var array<int, string>
     */
    private const BLOOD_TYPES = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];

    /**
     * One round of donations: what each was drawn into and separated into.
     *
     * Every component appears exactly once, so each round adds one unit of
     * each. A bag holds three components at most (BloodBagType), which is why
     * a round takes three donations.
     *
     * @var array<int, array{0: string, 1: array<string, int>}>
     */
    private const ROUND = [
        ['triple', ['Packed RBC' => 250, 'Platelet Concentrate' => 50, 'Fresh Frozen Plasma' => 200]],
        ['triple', ['Washed RBC' => 200, 'Cryoprecipitate' => 20, 'Cryosupernate' => 180]],
        ['single', ['Whole Blood' => 450]],
    ];

    /**
     * How long ago each donation was drawn, in rotation, so expiry dates spread
     * from a few days out to many months. Capped per donation below its
     * shortest shelf life, so nothing is seeded already expired.
     *
     * @var array<int, int>
     */
    private const DAYS_AGO = [1, 3, 6, 10, 15, 21, 28, 33, 2, 38];

    /**
     * Where each component is shelved.
     *
     * @var array<string, array<int, string>>
     */
    private const SHELVES = [
        'Whole Blood' => ['Cold Storage A-1', 'Cold Storage A-2'],
        'Packed RBC' => ['Cold Storage A-2', 'Cold Storage A-3', 'Cold Storage B-1'],
        'Washed RBC' => ['Cold Storage B-2'],
        'Platelet Concentrate' => ['Platelet Agitator 1'],
        'Fresh Frozen Plasma' => ['Freezer A', 'Freezer B'],
        'Cryoprecipitate' => ['Freezer A'],
        'Cryosupernate' => ['Freezer B'],
    ];

    private const FIRST_NAMES = [
        'Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Kristine', 'John Paul', 'Angelica', 'Carlo', 'Patricia',
        'Miguel', 'Camille', 'Rafael', 'Bea', 'Paolo', 'Nicole', 'Jerome', 'Andrea', 'Kevin', 'Joy',
    ];

    private const LAST_NAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Villanueva', 'Ramos', 'Aquino', 'Castillo',
        'Fernandez', 'Navarro', 'Torres', 'Flores', 'Gonzales', 'Lim', 'Tan', 'Rivera', 'Domingo', 'Salazar',
    ];

    /**
     * @param  int  $unitsPerComponent  Smaller in tests, where 50 of everything is slow.
     */
    public function __construct(
        private readonly DonorService $donorService,
        private readonly int $unitsPerComponent = self::UNITS_PER_COMPONENT
    ) {}

    public function run(): void
    {
        $facilities = Facility::query()
            ->where('status', FacilityStatus::Approved)
            ->whereHas('facilityType', fn ($query) => $query->where('name', FacilityTypeName::BloodCenter->value))
            ->orderBy('id')
            ->get();

        $bloodTypes = BloodType::query()->pluck('id', 'code');
        $components = BloodComponent::query()->pluck('id', 'name');

        if ($bloodTypes->isEmpty() || $components->isEmpty()) {
            $this->command?->warn('Blood types or components are missing. Run DatabaseSeeder first; skipping demo inventory.');

            return;
        }

        // Demo donors are registered with a random password nobody holds, so
        // the hash needs no strength; at the configured cost, the 1,200 donors
        // a centre needs for 50 of everything take minutes.
        $hasher = Hash::driver();
        $lowered = $hasher instanceof BcryptHasher;

        if ($lowered) {
            $hasher->setRounds(4);
        }

        try {
            foreach ($facilities as $facility) {
                $this->seedFacility($facility, $bloodTypes, $components);
            }
        } finally {
            if ($lowered) {
                $hasher->setRounds((int) config('hashing.bcrypt.rounds', 12));
            }
        }
    }

    /**
     * @param  Collection<string, int>  $bloodTypes
     * @param  Collection<string, int>  $components
     */
    private function seedFacility(Facility $facility, Collection $bloodTypes, Collection $components): void
    {
        $staff = User::query()->where('facility_id', $facility->id)->orderBy('id')->first();

        if ($staff === null) {
            $this->command?->warn("{$facility->name}: no staff account to record against; skipped.");

            return;
        }

        $shelfLife = FacilityBloodComponent::query()
            ->where('facility_id', $facility->id)
            ->whereNotNull('shelf_life_days')
            ->pluck('shelf_life_days', 'component_id');

        if ($shelfLife->isEmpty()) {
            $this->command?->warn("{$facility->name}: no component shelf life configured; skipped.");

            return;
        }

        $sequence = 0;
        $created = 0;

        foreach (self::BLOOD_TYPES as $code) {
            if (! $bloodTypes->has($code)) {
                continue;
            }

            $n = 0;

            for ($round = 1; $round <= $this->unitsPerComponent; $round++) {
                foreach (self::ROUND as [$bag, $breakdown]) {
                    $n++;
                    $sequence++;

                    // Only what this facility can put a date on.
                    $yield = [];

                    foreach ($breakdown as $name => $volume) {
                        $componentId = $components->get($name);

                        if ($componentId !== null && $shelfLife->has($componentId)) {
                            $yield[$name] = ['id' => $componentId, 'volume' => $volume, 'days' => (int) $shelfLife->get($componentId)];
                        }
                    }

                    if ($yield === []) {
                        continue;
                    }

                    $donor = $this->demoDonor($facility, $code, $n, $sequence);

                    if (Donation::query()->where('donor_id', $donor->id)->where('facility_id', $facility->id)->exists()) {
                        continue;
                    }

                    $shortest = min(array_column($yield, 'days'));
                    $daysAgo = max(1, min(self::DAYS_AGO[$sequence % count(self::DAYS_AGO)], $shortest - 1));

                    $created += $this->seedDonation($facility, $staff, $donor, $bloodTypes->get($code), $code, $bag, $yield, $daysAgo, $sequence);
                }
            }
        }

        $this->command?->info("{$facility->name}: {$created} available units seeded.");
    }

    /**
     * Find or register the demo donor for one slot, through the real registration path.
     */
    private function demoDonor(Facility $facility, string $code, int $n, int $sequence): User
    {
        // strtr, not str_replace: the latter would re-replace the '-' it just wrote.
        $slug = Str::lower(strtr($code, ['+' => '-pos', '-' => '-neg']));
        $email = "demo-donor-f{$facility->id}-{$slug}-{$n}@redagos.test";

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $typeIndex = array_search($code, self::BLOOD_TYPES, true);

        $this->donorService->register([
            'first_name' => self::FIRST_NAMES[$sequence % count(self::FIRST_NAMES)],
            // Shifted each time the first names come round, so all 400 pairings are used.
            'last_name' => self::LAST_NAMES[($sequence * 7 + intdiv($sequence, count(self::FIRST_NAMES))) % count(self::LAST_NAMES)],
            'email' => $email,
            // 11 digits, unique per facility, type and slot.
            'phone' => sprintf('0998%d%02d%04d', $facility->id % 10, $typeIndex, $n),
            'password' => Str::random(32),
            'gender' => $sequence % 2 === 0 ? 'male' : 'female',
            'birth_date' => CarbonImmutable::today()->subYears(19 + ($sequence * 3) % 40)->toDateString(),
            'address' => 'Davao City',
            'blood_type' => $code,
        ], verified: true);

        return User::query()->where('email', $email)->firstOrFail();
    }

    /**
     * One completed, cleared donation and its available units.
     *
     * @param  array<string, array{id: int, volume: int, days: int}>  $yield
     */
    private function seedDonation(
        Facility $facility,
        User $staff,
        User $donor,
        int $bloodTypeId,
        string $code,
        string $bag,
        array $yield,
        int $daysAgo,
        int $sequence
    ): int {
        return DB::transaction(function () use ($facility, $staff, $donor, $bloodTypeId, $code, $bag, $yield, $daysAgo, $sequence): int {
            $drawnOn = OperationalDay::today()->subDays($daysAgo);
            // A morning at the centre, stored in the app's timezone like every
            // timestamp the counter writes.
            // Spread over the working day, never past it into the next.
            $screenedAt = $drawnOn->setTime(8, 30)->addMinutes(($sequence * 7) % 420)->setTimezone(config('app.timezone'));
            $startedAt = $screenedAt->addMinutes(20);
            $endedAt = $startedAt->addMinutes(12);
            $testedAt = $endedAt->addHours(5);
            $group = rtrim($code, '+-');

            $donation = Donation::query()->create([
                'donor_id' => $donor->id,
                'facility_id' => $facility->id,
                'donation_date' => $screenedAt,
                'status' => DonationStatus::Completed,
                'volume_ml' => 450,
            ]);

            DonationScreening::query()->create([
                'donation_id' => $donation->id,
                'facility_id' => $facility->id,
                'recorded_by' => $staff->id,
                'outcome' => ScreeningOutcome::Accepted,
                'systolic_bp' => 110 + ($sequence * 3) % 20,
                'diastolic_bp' => 70 + ($sequence * 2) % 12,
                'pulse_bpm' => 64 + ($sequence * 5) % 20,
                'temperature_c' => 36.5,
                'weight_kg' => 55 + ($sequence * 4) % 30,
                'haemoglobin_g_dl' => 13.2 + ($sequence % 5) * 0.3,
                'fingerprick_blood_type_id' => $bloodTypeId,
                'screened_at' => $screenedAt,
            ]);

            BloodCollection::query()->create([
                'donation_id' => $donation->id,
                'facility_id' => $facility->id,
                'collected_by' => $staff->id,
                'blood_bag_type' => $bag,
                'donation_barcode' => 'DEMO-'.$donation->id,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'collection_datetime' => $endedAt,
            ]);

            DonationSerology::query()->create([
                'donation_id' => $donation->id,
                'hiv' => MarkerResult::NonReactive,
                'hbsag' => MarkerResult::NonReactive,
                'hcv' => MarkerResult::NonReactive,
                'syphilis' => MarkerResult::NonReactive,
                'malaria' => MarkerResult::NonReactive,
                'recorded_by' => $staff->id,
                'recorded_at' => $testedAt,
            ]);

            DonationImmunohematology::query()->create([
                'donation_id' => $donation->id,
                'blood_type_id' => $bloodTypeId,
                'forward_group' => $group,
                'reverse_group' => $group,
                'antibody_screen' => 'negative',
                'recorded_by' => $staff->id,
                'recorded_at' => $testedAt,
            ]);

            DonationTestResult::query()->create([
                'donation_id' => $donation->id,
                'recorded_by' => $staff->id,
                'blood_type_id' => $bloodTypeId,
                'result' => TestResult::Passed,
                'tested_at' => $testedAt,
            ]);

            foreach ([ClearanceKind::Tti->value => 'serology', ClearanceKind::Immunohematology->value => 'concordant_typing'] as $kind => $source) {
                DonationClearance::query()->create([
                    'donation_id' => $donation->id,
                    'kind' => $kind,
                    'issued_by' => $staff->id,
                    'issued_at' => $testedAt,
                    'source' => $source,
                ]);
            }

            $unit = 0;

            foreach ($yield as $name => $component) {
                DonationComponent::query()->create([
                    'donation_id' => $donation->id,
                    'component_id' => $component['id'],
                    'quantity' => 1,
                    'volume_ml' => $component['volume'],
                    'declared_by' => $staff->id,
                ]);

                $unit++;
                $shelves = self::SHELVES[$name] ?? ['Cold Storage A-1'];

                BloodUnit::query()->create([
                    // The same numbering intake generates: RA{facility}-{donation}-NN.
                    'id' => "RA{$facility->id}-{$donation->id}-".str_pad((string) $unit, 2, '0', STR_PAD_LEFT),
                    'facility_id' => $facility->id,
                    'component_id' => $component['id'],
                    'volume_ml' => $component['volume'],
                    'blood_type_id' => $bloodTypeId,
                    'donation_id' => $donation->id,
                    'storage_location' => $shelves[$sequence % count($shelves)],
                    'expiry_date' => $drawnOn->addDays($component['days'])->toDateString(),
                    'status' => BloodUnitStatus::Available,
                ]);
            }

            return $unit;
        });
    }
}
