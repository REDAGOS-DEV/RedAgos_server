<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\FacilityType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FacilitySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the blood centres the donor booking screen previously hardcoded.
     */
    public function run(): void
    {
        $bloodCenter = FacilityType::firstOrCreate(['name' => 'blood_center']);
        FacilityType::firstOrCreate(['name' => 'blood_bank']);

        $centers = [
            ['Sub-National Blood Center', 'Davao City', 'Mon - Fri 8 AM - 3 PM', '08:00', '15:00'],
            ['PRC Davao Blood Services', 'Davao City', 'Mon - Sat 7 AM - 4 PM', '07:00', '16:00'],
            ['SPMC Blood Bank', 'Davao City', '24/7', '00:00', '23:30'],
        ];

        foreach ($centers as [$name, $address, $hours, $startsAt, $endsAt]) {
            Facility::updateOrCreate(
                ['facility_type_id' => $bloodCenter->id, 'name' => $name],
                [
                    'address' => $address,
                    'operating_hours' => $hours,
                    'is_accepting_donations' => true,
                    'slot_capacity' => 4,
                    'slot_interval_minutes' => 30,
                    'slots_start_at' => $startsAt,
                    'slots_end_at' => $endsAt,
                ]
            );
        }
    }
}
