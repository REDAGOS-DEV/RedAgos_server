<?php

namespace App\Console\Commands;

use App\Enums\FacilityTypeName;
use App\Models\Facility;
use App\Service\AuditLogger;
use App\Service\XenditGateway;
use Illuminate\Console\Command;

/**
 * Link a blood centre to its Xendit sub-account, or unlink it.
 *
 * Each blood centre collects GCash payments into its own XenPlatform
 * sub-account under the RedAgos master account. The sub-account is created by
 * the platform operator in Xendit; this records its id so checkouts for that
 * centre are opened on its behalf. Without one, gateway checkout is simply
 * unavailable at that centre and cash carries on as before.
 */
class SetFacilityXenditAccount extends Command
{
    protected $signature = 'facility:set-xendit-account
        {facility : The blood centre\'s facility id}
        {account? : The Xendit sub-account (business) id}
        {--clear : Unlink the centre from its sub-account}';

    protected $description = 'Link a blood centre to its Xendit sub-account for GCash checkout';

    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $facility = Facility::query()->with('facilityType')->find((int) $this->argument('facility'));

        if ($facility === null) {
            $this->error('No facility has that id.');

            return self::FAILURE;
        }

        if ($facility->facilityType?->name !== FacilityTypeName::BloodCenter->value) {
            $this->error("{$facility->name} is not a blood centre; only blood centres collect payments.");

            return self::FAILURE;
        }

        $account = $this->option('clear') ? null : trim((string) $this->argument('account'));

        if ($account === '') {
            $this->error('Give the sub-account id, or --clear to unlink the centre.');

            return self::FAILURE;
        }

        if ($account !== null && strlen($account) > 64) {
            $this->error('That does not look like a Xendit account id: it is longer than 64 characters.');

            return self::FAILURE;
        }

        // The gateway reads this word as "the master account itself".
        if ($account === XenditGateway::MAIN_ACCOUNT) {
            $this->error('"'.XenditGateway::MAIN_ACCOUNT.'" is not a sub-account id. Give the id Xendit shows for the sub-account.');

            return self::FAILURE;
        }

        if ($account !== null && Facility::query()->where('xendit_sub_account_id', $account)->whereKeyNot($facility->id)->exists()) {
            $this->error('That sub-account is already linked to another facility.');

            return self::FAILURE;
        }

        $previous = $facility->xendit_sub_account_id;
        $facility->forceFill(['xendit_sub_account_id' => $account])->save();

        $this->auditLogger->record(null, 'facility.xendit_account_set', $facility, [
            'facility_id' => $facility->id,
            'linked' => $account !== null,
            'replaced' => $previous !== null && $previous !== $account,
            'source' => 'console:facility:set-xendit-account',
        ]);

        $this->info($account === null
            ? "{$facility->name} is unlinked; GCash checkout is unavailable there."
            : "{$facility->name} now collects GCash payments into sub-account {$account}.");

        return self::SUCCESS;
    }
}
