<?php

namespace App\Service;

use App\Models\Donation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class DonationHistoryService
{
    /**
     * List the donor's own donations, newest first, with summary statistics.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(User $user, array $filters): array
    {
        $profile = $user->donorProfile()->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'donor' => ['The authenticated user does not have a donor profile.'],
            ]);
        }

        $donations = $this->query($profile->donor_id, $filters)
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        $completedCount = Donation::where('donor_id', $profile->donor_id)->completed()->count();

        return [
            'donations' => collect($donations->items())
                ->map(fn (Donation $donation): array => $this->format($donation))
                ->all(),
            'stats' => [
                'total_donations' => $completedCount,
                'lives_impacted' => $completedCount * (int) config('donation.lives_per_donation'),
            ],
            'meta' => [
                'page' => $donations->currentPage(),
                'per_page' => $donations->perPage(),
                'total' => $donations->total(),
                'last_page' => $donations->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(int $donorId, array $filters): Builder
    {
        return Donation::query()
            ->with('facility')
            ->where('donor_id', $donorId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('donation_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('donation_date', '<=', $to))
            ->orderByDesc('donation_date')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function format(Donation $donation): array
    {
        return [
            'id' => $donation->id,
            'center_name' => $donation->facility?->name,
            'address' => $donation->facility?->address,
            'donated_on' => $donation->donation_date->toDateString(),
            'time' => $donation->donation_date->format('H:i'),
            'blood_type' => $donation->donorProfile?->bloodType?->code,
            'volume_ml' => $donation->volume_ml,
            'status' => $donation->status,
        ];
    }
}
