<?php

namespace App\Service;

use App\Models\BillingRevision;
use App\Models\Facility;
use App\Models\PaymentReceipt;
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
 *
 * A billing document reprints exactly as it was issued, logo included: a
 * Statement of Account records the file in issuer_logo_path, a receipt in its
 * snapshot. A replaced or removed logo is therefore deleted only once no
 * issued document prints it, and the *ForPath() methods serve the exact file
 * a document names.
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
        if ($previous && $previous !== $path) {
            $this->discard($previous);
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

        if ($previous) {
            $this->discard($previous);
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
     * A short-lived signed link to the exact logo file a billing document was issued with.
     *
     * Falls back to the facility's current logo for a document that recorded
     * none — issued before logos were frozen, or while the centre had none —
     * and for one whose file is gone.
     */
    public function urlForPath(?Facility $facility, ?string $path): ?string
    {
        if ($facility === null) {
            return null;
        }

        if (! $this->isLogoFile($path)) {
            return $this->urlFor($facility);
        }

        // The path is part of what is signed, so it cannot be swapped for another file.
        return URL::temporarySignedRoute(
            'blood-center.facility.logo',
            now()->addMinutes(30),
            ['facility' => $facility->id, 'path' => $path]
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

        return $this->dataUri($facility->logo_path);
    }

    /**
     * The exact logo file a billing document was issued with, as a data URI, falling back as urlForPath() does.
     */
    public function dataUriForPath(?Facility $facility, ?string $path): ?string
    {
        return $this->isLogoFile($path) ? $this->dataUri($path) : $this->dataUriFor($facility);
    }

    /**
     * Stream a logo for the signed route: the facility's current one, or the exact file the signed link names.
     */
    public function response(Facility $facility, ?string $path = null): mixed
    {
        if ($path !== null) {
            abort_unless($this->isLogoFile($path), 404);
        } else {
            abort_unless($this->hasLogo($facility), 404);
            $path = $facility->logo_path;
        }

        return Storage::disk(self::DISK)->response($path, null, [
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
     * Whether a path names a stored logo file, and nothing else on the disk.
     */
    private function isLogoFile(?string $path): bool
    {
        return $path !== null
            && str_starts_with($path, self::DIRECTORY.'/')
            && ! str_contains($path, '..')
            && Storage::disk(self::DISK)->exists($path);
    }

    private function dataUri(string $path): string
    {
        $disk = Storage::disk(self::DISK);
        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
    }

    /**
     * Delete a logo the facility no longer uses, unless an issued billing document still prints it.
     */
    private function discard(string $path): void
    {
        $printed = BillingRevision::query()->where('issuer_logo_path', $path)->exists()
            || PaymentReceipt::query()->where('snapshot->issuing_facility->logo_path', $path)->exists();

        if (! $printed && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
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
