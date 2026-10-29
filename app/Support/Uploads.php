<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * The private disk every uploaded or generated file is kept on.
 *
 * Donor and staff photos, identity documents, facility logos and seals, and
 * hospital stock sheets. Locally that is the private `local` disk. A host whose
 * own disk is wiped on every deploy (Laravel Cloud) keeps them in a bucket
 * instead, named by UPLOADS_DISK: see config/filesystems.php.
 *
 * Methods rather than a constant because the name comes from config.
 */
final class Uploads
{
    /**
     * The disk's name, for UploadedFile::store().
     */
    public static function diskName(): string
    {
        return (string) config('filesystems.uploads', 'local');
    }

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }
}
