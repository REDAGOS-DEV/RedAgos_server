<?php

namespace Database\Seeders;

use App\Enums\BloodUnitStatus;
use App\Enums\ClearanceKind;
use App\Enums\DonationStatus;
use App\Enums\FacilityStatus;
use App\Enums\FacilityTypeName;
use App\Enums\MarkerResult;
use App\Enums\ScreeningOutcome;
use App\Enums\StaffRole;
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
use App\Support\BagNumbers;
use App\Support\OperationalDay;
use App\Support\ReservedContacts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Available stock of every blood type, for each blood centre to work against:
 * 50 units of every component in every blood type (UNITS_PER_COMPONENT).
 *
 * DEMO DATA. Not called from DatabaseSeeder: run it on purpose with
 * `php artisan db:seed --class=DemoInventorySeeder`.
 *
 * Each unit is built the way the real workflow builds one — a donor, an
 * accepted screening, a recorded collection with its own barcode sticker,
 * both laboratory sections, both clearances, the components Processing
 * declared, the final labels — because blood_units requires a donation, and a
 * unit with no clearances behind it is stock the dispatch gate would rightly
 * refuse to issue. Each bag is numbered as Stock Intake numbers it
 * (BagNumbers): SNBC-21000123-PRBC.
 *
 * Seeds every approved blood centre that has an abbreviation, a staff member
 * to attribute the records to, and a configured shelf life for at least one
 * component. Shelf life is never invented here: a component the facility has
 * not configured is left out, as intake itself would refuse it.
 *
 * Demo donors reach no one: an example.com address and a number from the
 * demo block (ReservedContacts), so nothing RedAgos sends them goes out.
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
     * Where demo barcode stickers start: eight digits, like a real roll.
     * Offset by the donation id, so no two donations anywhere share one.
     */
    public const BARCODE_BASE = 21000000;

    /**
     * @var array<int, string>
     */
    private const BLOOD_TYPES = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];

    /**
     * One round of donations: what each was drawn into and separated into.
     *
     * Every component appears exactly once, so each round adds one unit of
     * each. A bag holds three components at most (BloodBagType), which is why
     * a round takes three donations. Platelets keep five days, so the bag
     * they come from is never older than four; Packed RBC is kept away from
     * them so its 42 days spread from fresh to about to expire.
     *
     * @var array<int, array{0: string, 1: array<string, int>}>
     */
    private const ROUND = [
        ['triple', ['Packed RBC' => 250, 'Fresh Frozen Plasma' => 200, 'Cryoprecipitate' => 20]],
        ['triple', ['Washed RBC' => 200, 'Platelet Concentrate' => 50, 'Cryosupernate' => 180]],
        ['single', ['Whole Blood' => 450]],
    ];

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

    /**
     * Who records each step, by role, so the records read like the real
     * workflow's. A centre without the role falls back to its first staff
     * member.
     *
     * @var array<string, StaffRole>
     */
    private const STEP_ROLES = [
        'screening' => StaffRole::ScreeningPhysician,
        'collection' => StaffRole::Phlebotomist,
        'serology' => StaffRole::SerologyTechnologist,
        'typing' => StaffRole::LabSupervisor,
        'processing' => StaffRole::ComponentTechnologist,
        'issuance' => StaffRole::InventoryControlOfficer,
    ];

    private const FIRST_NAMES = [
        'Juan', 'Maria', 'Jose', 'Ana', 'Mark', 'Kristine', 'John Paul', 'Angelica', 'Carlo', 'Patricia',
        'Miguel', 'Camille', 'Rafael', 'Bea', 'Paolo', 'Nicole', 'Jerome', 'Andrea', 'Kevin', 'Joy',
        'Ramon', 'Liza', 'Dennis', 'Grace', 'Arnel', 'Rowena', 'Noel', 'Cristina', 'Jun', 'Marites',
    ];

    private const LAST_NAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Villanueva', 'Ramos', 'Aquino', 'Castillo',
        'Fernandez', 'Navarro', 'Torres', 'Flores', 'Gonzales', 'Lim', 'Tan', 'Rivera', 'Domingo', 'Salazar',
        'Pascual', 'Manalo', 'Cabrera', 'Soriano', 'Ocampo', 'Velasco', 'Morales', 'Espinosa', 'Panganiban', 'Lacson',
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
        if ($facility->abbreviation === null) {
            $this->command?->warn("{$facility->name}: no abbreviation to number its bags with; skipped.");

            return;
        }

        $staff = User::query()->where('facility_id', $facility->id)->orderBy('id')->get();

        if ($staff->isEmpty()) {
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

        $by = $this->staffByStep($staff);
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

                    $created += $this->seedDonation($facility, $by, $donor, $bloodTypes->get($code), $code, $bag, $yield, $this->daysAgo($yield, $sequence), $sequence);
                }
            }
        }

        $this->command?->info("{$facility->name}: {$created} available units seeded.");
    }

    /**
     * How long ago a donation was drawn: spread over every day its shortest-
     * lived bag allows, so expiry dates run from tomorrow to the full shelf
     * life. Never as old as that shelf life, so nothing is seeded expired.
     *
     * The step (37) shares no factor with the spans used here, so successive
     * donations walk every day of the span before repeating one.
     *
     * @param  array<string, array{id: int, volume: int, days: int}>  $yield
     */
    private function daysAgo(array $yield, int $sequence): int
    {
        $span = max(1, min(array_column($yield, 'days')) - 1);

        return 1 + (($sequence * 37) % $span);
    }

    /**
     * The staff member who records each step, by STEP_ROLES.
     *
     * @param  Collection<int, User>  $staff
     * @return array<string, User>
     */
    private function staffByStep(Collection $staff): array
    {
        $by = [];

        foreach (self::STEP_ROLES as $step => $role) {
            $by[$step] = $staff->first(fn (User $user): bool => $user->hasStaffRole($role)) ?? $staff->first();
        }

        return $by;
    }

    /**
     * Find or register the demo donor for one slot, through the real registration path.
     */
    private function demoDonor(Facility $facility, string $code, int $n, int $sequence): User
    {
        // strtr, not str_replace: the latter would re-replace the '-' it just wrote.
        $slug = Str::lower(strtr($code, ['+' => '-pos', '-' => '-neg']));
        $centre = Str::lower((string) $facility->abbreviation);
        $email = "donor.{$centre}.{$slug}.{$n}@example.com";

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $typeIndex = (int) array_search($code, self::BLOOD_TYPES, true);

        $this->donorService->register([
            'first_name' => self::FIRST_NAMES[$sequence % count(self::FIRST_NAMES)],
            // Shifted each time the first names come round, so every pairing is used.
            'last_name' => self::LAST_NAMES[($sequence * 7 + intdiv($sequence, count(self::FIRST_NAMES))) % count(self::LAST_NAMES)],
            'email' => $email,
            // The demo block reaches no one. Unique per facility, type and slot.
            'phone' => ReservedContacts::demoMobile(1_000_000 + ($facility->id % 90) * 100_000 + $typeIndex * 10_000 + $n),
            'password' => Str::random(32),
            'gender' => $sequence % 2 === 0 ? 'male' : 'female',
            'birth_date' => CarbonImmutable::today()->subYears(19 + ($sequence * 3) % 40)->subDays($sequence % 300)->toDateString(),
            'address' => 'Davao City',
            'blood_type' => $code,
        ], verified: true);

        return User::query()->where('email', $email)->firstOrFail();
    }

    /**
     * One completed, cleared, labelled donation and its available units.
     *
     * @param  array<string, User>  $by
     * @param  array<string, array{id: int, volume: int, days: int}>  $yield
     */
    private function seedDonation(
        Facility $facility,
        array $by,
        User $donor,
        int $bloodTypeId,
        string $code,
        string $bag,
        array $yield,
        int $daysAgo,
        int $sequence
    ): int {
        return DB::transaction(function () use ($facility, $by, $donor, $bloodTypeId, $code, $bag, $yield, $daysAgo, $sequence): int {
            $drawnOn = OperationalDay::today()->subDays($daysAgo);
            // A morning at the centre, stored in the app's timezone like every
            // timestamp the counter writes.
            // Spread over the working day, never past it into the next.
            $screenedAt = $drawnOn->setTime(8, 30)->addMinutes(($sequence * 7) % 420)->setTimezone(config('app.timezone'));
            $startedAt = $screenedAt->addMinutes(20);
            $endedAt = $startedAt->addMinutes(12);
            $testedAt = $endedAt->addHours(5);
            $labelledAt = $testedAt->addHour();
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
                'recorded_by' => $by['screening']->id,
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
                'collected_by' => $by['collection']->id,
                'blood_bag_type' => $bag,
                // Digits only, like a real sticker (2026-11-11), and its own
                // per donation, so every bag number is the donation's.
                'donation_barcode' => (string) (self::BARCODE_BASE + $donation->id),
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
                'recorded_by' => $by['serology']->id,
                'recorded_at' => $testedAt,
            ]);

            DonationImmunohematology::query()->create([
                'donation_id' => $donation->id,
                'blood_type_id' => $bloodTypeId,
                'forward_group' => $group,
                'reverse_group' => $group,
                'antibody_screen' => 'negative',
                'recorded_by' => $by['typing']->id,
                'recorded_at' => $testedAt,
            ]);

            DonationTestResult::query()->create([
                'donation_id' => $donation->id,
                'recorded_by' => $by['typing']->id,
                'blood_type_id' => $bloodTypeId,
                'result' => TestResult::Passed,
                'tested_at' => $testedAt,
            ]);

            foreach ([ClearanceKind::Tti->value => 'serology', ClearanceKind::Immunohematology->value => 'concordant_typing'] as $kind => $source) {
                DonationClearance::query()->create([
                    'donation_id' => $donation->id,
                    'kind' => $kind,
                    'issued_by' => $by['typing']->id,
                    'issued_at' => $testedAt,
                    'source' => $source,
                ]);
            }

            foreach ($yield as $component) {
                DonationComponent::query()->create([
                    'donation_id' => $donation->id,
                    'component_id' => $component['id'],
                    'quantity' => 1,
                    'volume_ml' => $component['volume'],
                    'declared_by' => $by['processing']->id,
                ]);
            }

            // Processing printed the final labels; Issuance booked the bags in.
            $donation->forceFill(['labelled_at' => $labelledAt, 'labelled_by' => $by['processing']->id])->save();

            // Numbered exactly as Stock Intake numbers them.
            $slots = BagNumbers::slots($donation);
            $units = 0;

            foreach ($yield as $name => $component) {
                $slot = $slots[$component['id']][0];
                $shelves = self::SHELVES[$name] ?? ['Cold Storage A-1'];

                BloodUnit::query()->create([
                    'id' => $slot['bag_number'],
                    'facility_id' => $facility->id,
                    'component_id' => $component['id'],
                    'volume_ml' => $component['volume'],
                    'blood_type_id' => $bloodTypeId,
                    'donation_id' => $donation->id,
                    'storage_location' => $shelves[$sequence % count($shelves)],
                    'expiry_date' => $drawnOn->addDays($component['days'])->toDateString(),
                    'status' => BloodUnitStatus::Available,
                    'released_at' => $labelledAt->addHour(),
                    'released_by' => $by['issuance']->id,
                ]);

                $units++;
            }

            return $units;
        });
    }
}
