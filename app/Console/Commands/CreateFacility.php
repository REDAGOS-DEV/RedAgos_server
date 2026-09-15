<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PromptsForAccountDetails;
use App\Console\Commands\Concerns\ResolvesActingAdministrator;
use App\Http\Requests\StoreFacilityRequest;
use App\Models\User;
use App\Service\FacilityManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Create a facility and the primary account that runs its portal.
 *
 * The console counterpart to POST /api/admin/facilities, and it exists for the
 * same reason admin:create does: that endpoint sits behind an administrator who
 * may not exist yet, or who has no browser session on a machine that only holds
 * database credentials.
 *
 * It is a front end onto FacilityManagementService, not a second way to build a
 * facility. Everything deciding whether a facility is safe to operate — the
 * approval trail, the role granted, whether the slot columns apply — stays in
 * that service, and the rules are read off StoreFacilityRequest rather than
 * restated here, so the two paths cannot drift on what a valid facility is.
 *
 * An administrator is still named as the creator, because facilities.approved_by
 * records who vouched for the organisation. A console operator is a person, not
 * an exemption from that.
 */
class CreateFacility extends Command
{
    use PromptsForAccountDetails, ResolvesActingAdministrator;

    protected $signature = 'facility:create
                            {--type= : blood_center or blood_bank}
                            {--name= : Registered facility name}
                            {--doh-license= : DOH license number}
                            {--address= : Street address}
                            {--email= : Facility contact address}
                            {--phone= : Facility contact number}
                            {--description= : Optional blurb shown to donors}
                            {--operating-hours= : Free text, such as Mon - Fri 8 AM - 3 PM}
                            {--slots-start= : Booking window opens, HH:MM (blood centers only)}
                            {--slots-end= : Booking window closes, HH:MM (blood centers only)}
                            {--account-first-name= : Primary account given name}
                            {--account-last-name= : Primary account surname}
                            {--account-position= : Primary account job title}
                            {--account-email= : Primary account sign-in address}
                            {--account-phone= : Primary account mobile number}
                            {--account-username= : Defaults to one derived from the email}
                            {--account-password= : Leave unset to be prompted; an argument is visible in shell history}
                            {--admin= : Email of the administrator recorded as creating it}
                            {--verified : Mark the primary account verified instead of waiting on the emailed link}';

    protected $description = 'Create a facility and its primary account';

    public function __construct(
        private readonly FacilityManagementService $facilityManagementService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $admin = $this->resolveActingAdministrator();

        if ($admin === null) {
            return self::FAILURE;
        }

        $payload = $this->collectPayload();

        if ($payload === null) {
            return self::FAILURE;
        }

        // Read off the FormRequest rather than restated: an administrator
        // creating a facility in the portal and an operator creating one here
        // must be held to the same definition of a valid facility, and a second
        // copy of these rules would quietly stop being that.
        $request = StoreFacilityRequest::create('/', 'POST', $payload);
        $request->setContainer($this->laravel);

        $validator = Validator::make($payload, $request->rules(), $request->messages());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $created = $this->facilityManagementService->create($admin, $payload);
        $facility = $created['data'];
        $account = $facility['primary_account'];
        $verified = (bool) $this->option('verified');

        if ($verified) {
            User::query()->where('uuid', $account['uuid'])->sole()->markEmailAsVerified();
        }

        $this->info($facility['name'].' created.');
        $this->table(['Field', 'Value'], [
            ['Facility', $facility['name'].' (#'.$facility['id'].')'],
            ['Type', $facility['facility_type_label']],
            ['Status', $facility['status']],
            ['Primary account', $account['email']],
            ['Can sign in', $verified
                ? 'Yes'
                : 'Not until the emailed verification link is opened'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Gather the facility and its primary account, asking for what was not passed.
     *
     * Returns null when the two password entries disagree.
     *
     * @return array<string, mixed>|null
     */
    private function collectPayload(): ?array
    {
        $facility = [
            'facility_type' => Str::lower($this->optionOrAsk('type', 'Facility type (blood_center or blood_bank)')),
            'name' => $this->optionOrAsk('name', 'Facility name'),
            'doh_license_number' => $this->optionOrAsk('doh-license', 'DOH license number'),
            'address' => $this->optionOrAsk('address', 'Address'),
            'email' => $this->emailOrAsk('email', 'Facility email'),
            'phone' => $this->phoneOrAsk('phone', 'Facility phone'),
            'description' => $this->optionOrNull('description'),
            'operating_hours' => $this->optionOrNull('operating-hours'),
            'slots_start_at' => $this->optionOrNull('slots-start'),
            'slots_end_at' => $this->optionOrNull('slots-end'),
        ];

        $account = [
            'first_name' => $this->optionOrAsk('account-first-name', 'Primary account first name'),
            'last_name' => $this->optionOrAsk('account-last-name', 'Primary account last name'),
            'position' => $this->optionOrAsk('account-position', 'Primary account position'),
            'email' => $this->emailOrAsk('account-email', 'Primary account email'),
            'phone' => $this->phoneOrAsk('account-phone', 'Primary account phone'),
            'username' => $this->optionOrNull('account-username'),
        ];

        $password = $this->passwordOrAsk('account-password', 'Primary account password');

        if ($password === null) {
            return null;
        }

        return [
            ...$facility,
            'primary_account' => [
                ...$account,
                'password' => $password,
                // The prompt already asked twice, and an operator who passed
                // --account-password meant it. The rule stays in force for the
                // HTTP path, so it is satisfied here rather than stripped.
                'password_confirmation' => $password,
            ],
        ];
    }
}
