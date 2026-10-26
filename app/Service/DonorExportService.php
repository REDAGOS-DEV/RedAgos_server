<?php

namespace App\Service;

use App\Models\FacilityDonor;
use App\Models\User;
use App\Repository\DonorDirectoryRepository;
use App\Support\DonorStanding;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Donors list as a spreadsheet: every donor the page's filters match, not
 * just the page on screen.
 *
 * CSV rather than .xlsx, so no spreadsheet library is needed; it opens in
 * Excel as a sheet. Three details make that true:
 *
 *  - A UTF-8 byte-order mark, without which Excel reads "Peñafrancia" as
 *    mojibake.
 *  - Phone numbers written the local way with spaces (0917 123 4567), which
 *    Excel keeps as text. "+639171234567" would become 6.39E+11, or be read
 *    as a formula.
 *  - Any cell that begins like a formula (= + - @) is prefixed with an
 *    apostrophe. Names, addresses and notes are typed by people, and a cell
 *    reading =HYPERLINK(...) would otherwise run when the sheet is opened.
 *
 * There is no City column: RedAgos keeps one address line, not a city. The
 * photo is a Yes/No rather than a link — a photo link is a signed URL that
 * expires in thirty minutes, and one that did not would put a donor's face
 * one forwarded spreadsheet away from anyone.
 */
class DonorExportService
{
    private const CHUNK = 300;

    private const HEADER = [
        'DonorID', 'FullName', 'Gender', 'DOB', 'Age', 'BloodGroup', 'Phone', 'Email',
        'Address', 'Occupation', 'LastDonation', 'TotalDonations', 'DeferralStatus',
        'Photo', 'Status', 'Notes', 'CreatedAt',
    ];

    private const GENDER = [
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other',
        'prefer_not_to_say' => 'Prefer not to say',
    ];

    public function __construct(
        private readonly DonorDirectoryRepository $donorDirectoryRepository,
        private readonly DonorStandingResolver $donorStandingResolver,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * @param  array<string, mixed>  $filters  the page's filters and sort
     */
    public function csv(User $staff, array $filters): StreamedResponse
    {
        $staff->loadMissing('facility');

        $facility = $staff->facility ?? throw new HttpResponseException(response()->json([
            'message' => 'This account is not linked to a facility.',
            'code' => 'facility_missing',
        ], 404));

        $query = $this->donorDirectoryRepository->facilityDonorQuery($facility->id, $filters);

        // Who took the list, how much of it and with which filters. Not the
        // rows themselves: the audit trail is not a second copy of them.
        $this->auditLogger->record($staff, 'donors.exported', $facility, [
            'rows' => (clone $query)->count(),
            'filters' => array_intersect_key($filters, array_flip(['search', 'blood_type_id', 'status'])),
        ]);

        $filename = 'donors-'.Str::slug($facility->name).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $facility): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADER, escape: '');

            $query->chunk(self::CHUNK, function ($donors) use ($out, $facility): void {
                $standings = $this->donorStandingResolver->forDonors($facility->id, $donors);
                $records = $this->donorDirectoryRepository->facilityRecordsFor($facility->id, $donors->pluck('id')->all());

                foreach ($donors as $donor) {
                    fputcsv($out, $this->row($donor, $standings[$donor->id], $records[$donor->id] ?? null), escape: '');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function row(User $donor, DonorStanding $standing, ?FacilityDonor $record): array
    {
        $profile = $donor->donorProfile;
        $birth = $profile?->birth_date;

        return array_map(fn (mixed $value): string => $this->cell($value), [
            'DONOR-'.str_pad((string) $donor->id, 6, '0', STR_PAD_LEFT),
            trim($donor->first_name.' '.$donor->last_name),
            self::GENDER[$profile?->gender] ?? '',
            $birth?->toDateString(),
            $birth?->age,
            $profile?->bloodType?->code,
            $this->phone($donor->phone),
            $donor->email,
            $profile?->address,
            $profile?->occupation,
            $standing->lastDonationAt?->toDateString(),
            $standing->totalDonations,
            match ($standing->deferralKind()) {
                'permanent' => 'Permanent',
                'indefinite' => 'Indefinite',
                'temporary' => 'Temporary',
                default => 'None',
            },
            filled($profile?->profile_image_path) ? 'Yes' : 'No',
            ($record?->status?->label()) ?? 'Active',
            $record?->notes,
            $donor->created_at?->toDateString(),
        ]);
    }

    /**
     * +639171234567 → 0917 123 4567. Anything else is left as stored.
     */
    private function phone(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return preg_match('/^\+63(9\d{2})(\d{3})(\d{4})$/', $phone, $m) === 1
            ? "0{$m[1]} {$m[2]} {$m[3]}"
            : $phone;
    }

    private function cell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
