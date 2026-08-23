<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\MobileEvent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class MobileEventSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed sample mobile blood drives for the donor booking screen.
     *
     * Dates are offsets from today rather than fixed calendar dates, because
     * upcomingDrives() only returns events on or after the current date. Fixed
     * dates would quietly drop out of the catalogue as they aged, leaving the
     * booking screen empty with no obvious cause.
     */
    public function run(): void
    {
        $facilities = Facility::query()->orderBy('id')->get();

        if ($facilities->isEmpty()) {
            $this->command?->warn('No facilities found. Run FacilitySeeder first; skipping blood drives.');

            return;
        }

        $today = Carbon::today();

        // [name, location, days from today, max capacity]
        // A null capacity means unlimited, which driveStatus() never marks Full.
        $drives = [
            ['UM Matina Bloodletting Drive', 'University of Mindanao, Matina, Davao City', 0, 60],
            ['SM Lanang Premier Community Drive', 'SM Lanang Premier, J.P. Laurel Ave, Davao City', 3, 80],
            ['Ateneo de Davao Red Cross Youth Drive', 'Ateneo de Davao University, E. Jacinto St, Davao City', 7, 45],
            ['Davao City Hall Employees Drive', 'Davao City Hall, San Pedro St, Davao City', 14, 50],
            ['Gaisano Mall Bajada Weekend Drive', 'Gaisano Mall of Davao, Bajada, Davao City', 21, null],
            ['Barangay Buhangin Community Drive', 'Buhangin Barangay Hall, Davao City', 30, 35],
        ];

        foreach ($drives as $index => [$name, $location, $offsetDays, $capacity]) {
            MobileEvent::updateOrCreate(
                ['name' => $name],
                [
                    // Spread the drives across whichever centres exist so each
                    // facility has something attached to it.
                    'facility_id' => $facilities->get($index % $facilities->count())->id,
                    'location' => $location,
                    'event_date' => $today->copy()->addDays($offsetDays),
                    'max_capacity' => $capacity,
                ]
            );
        }

        $this->command?->info('Seeded '.count($drives).' mobile blood drives.');
    }
}
