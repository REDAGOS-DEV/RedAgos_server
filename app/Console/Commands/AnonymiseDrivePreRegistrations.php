<?php

namespace App\Console\Commands;

use App\Service\DrivePreRegistrationService;
use Illuminate\Console\Command;

/**
 * Anonymise the drive pre-registrations never turned into a booking, about 90
 * days after their drive (project owner, 2026-11-11). The rows stay, without
 * names or contacts, so a past drive's figures do not change.
 *
 * Idempotent: an entry anonymised once is never matched again.
 */
class AnonymiseDrivePreRegistrations extends Command
{
    protected $signature = 'drives:anonymise-pre-registrations';

    protected $description = 'Anonymise drive pre-registrations never turned into a booking, once their drive is past the retention period';

    public function __construct(
        private readonly DrivePreRegistrationService $preRegistrationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->preRegistrationService->anonymiseExpired();

        $this->info("Anonymised {$count} drive pre-registration(s).");

        return self::SUCCESS;
    }
}
