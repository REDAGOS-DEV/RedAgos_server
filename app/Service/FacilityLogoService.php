<?php

namespace App\Service;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Each facility's own logo: stored privately, served by signed link, embedded in PDFs.
 *
 * Follows the donor-avatar pattern. The file lives on the private `local`
 * disk, so it is never reachable by guessing a path; the browser gets a
 * 30-minute signed URL, and a PDF gets the bytes inlined as a data URI
 * because dompdf is run with remote fetching off.
 *
 * Only a supervisor changes it (`center.configure`), and only for the facility
 * their own account belongs to — the facility is resolved from the token,
 * never from the request.
 */
class FacilityLogoService
{
    private const DIRECTORY = 'facility-logos';

    private const DISK = 'local';

    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Store a new logo for the caller's facility, replacing any previous one.
     *
     * @return array<string, mixed>
     */
    public function upload(User $staff, UploadedFile $logo): array
    {
        $facility = $this->requireFacility($staff);
        $previous = $facility->logo_path;

        $path = $logo->store(self::DIRECTORY, self::DISK);

        // Direct assignment rather than mass assignment: the path is decided
        // here, never taken from a request.
        $facility->logo_path = $path;
        $facility->save();

        // Only once the new path is saved, so a failed save never leaves the
        // facility pointing at a file that has been deleted.
        if ($previous && $previous !== $path && Storage::disk(self::DISK)->exists($previous)) {
            Storage::disk(self::DISK)->delete($previous);
        }

        $this->auditLogger->record($staff, 'center.logo_updated', $facility, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => 'Facility logo updated.',
            'logo_url' => $this->urlFor($facility),
        ];
    }

    /**
     * Remove the caller's facility logo.
     *
     * @return array<string, mixed>
     */
    public function remove(User $staff): array
    {
        $facility = $this->requireFacility($staff);
        $previous = $facility->logo_path;

        $facility->logo_path = null;
        $facility->save();

        if ($previous && Storage::disk(self::DISK)->exists($previous)) {
            Storage::disk(self::DISK)->delete($previous);
        }

        $this->auditLogger->record($staff, 'center.logo_removed', $facility, [
            'facility_id' => $facility->id,
        ]);

        return [
            'message' => 'Facility logo removed.',
            'logo_url' => null,
        ];
    }

    /**
     * A short-lived signed link to the logo, or null when there is none.
     */
    public function urlFor(?Facility $facility): ?string
    {
        if ($facility === null || ! $this->hasLogo($facility)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'blood-center.facility.logo',
            now()->addMinutes(30),
            // The path's name changes on every upload, so a replaced logo is
            // never served from a browser cache under the old link.
            ['facility' => $facility->id, 'v' => basename((string) $facility->logo_path)]
        );
    }

    /**
     * The logo as a data URI for dompdf, or null when there is none.
     */
    public function dataUriFor(?Facility $facility): ?string
    {
        if ($facility === null || ! $this->hasLogo($facility)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $mime = $disk->mimeType($facility->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($facility->logo_path));
    }

    /**
     * Stream the logo for the signed route.
     */
    public function response(Facility $facility): mixed
    {
        abort_unless($this->hasLogo($facility), 404);

        return Storage::disk(self::DISK)->response($facility->logo_path, null, [
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function hasLogo(Facility $facility): bool
    {
        return $facility->logo_path !== null
            && Storage::disk(self::DISK)->exists($facility->logo_path);
    }

    /**
     * The facility the caller acts for, resolved from the token rather than input.
     */
    private function requireFacility(User $staff): Facility
    {
        $staff->loadMissing('facility');

        return $staff->facility
            ?? throw new HttpResponseException(response()->json([
                'message' => 'This account is not linked to a facility.',
                'code' => 'facility_missing',
            ], 404));
    }
}
