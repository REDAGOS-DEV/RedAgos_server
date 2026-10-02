<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PromptsForAccountDetails;
use App\Http\Requests\RegisterDonorRequest;
use App\Service\DonorService;
use App\Support\AccountIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Register a donor from the console, verified and ready to sign in.
 *
 * The console counterpart to POST /api/donors/register. It is a front end onto
 * DonorService::register, not a second way to build a donor, and the rules are
 * read off RegisterDonorRequest rather than restated, so a donor made here is
 * held to the same definition as one who signed up in the portal.
 *
 * The one difference is verification. Self-registration mails a link because
 * nothing else proves the address; here the operator at the terminal is
 * vouching for the account, so it is activated on the spot and nothing is sent.
 */
class CreateDonor extends Command
{
    use PromptsForAccountDetails;

    protected $signature = 'donor:create
                            {--first-name= : Given name}
                            {--last-name= : Surname}
                            {--email= : Sign-in address}
                            {--phone= : Mobile number}
                            {--gender= : male, female, other or prefer_not_to_say}
                            {--birth-date= : YYYY-MM-DD; the donor must be at least 18}
                            {--address= : Home address}
                            {--blood-type= : A+, A-, B+, B-, AB+, AB-, O+ or O-; leave unset if unknown}
                            {--valid-id-type= : ID the donor will present at the counter, optional}
                            {--valid-id-number= : Number on that ID, required with --valid-id-type}
                            {--password= : Leave unset to be prompted; an argument is visible in shell history}';

    protected $description = 'Register a verified donor account';

    public function __construct(private readonly DonorService $donorService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->collectPayload();

        if ($payload === null) {
            return self::FAILURE;
        }

        // Read off the FormRequest rather than restated: a donor registered here
        // and one who signed up in the portal must be held to the same
        // definition of a valid donor, age limit included.
        $request = new RegisterDonorRequest;

        $validator = Validator::make($payload, $request->rules(), $request->messages());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $donor = $this->donorService->register($validator->validated(), verified: true)['data']['user'];

        $this->info(trim($donor['first_name'].' '.$donor['last_name']).' registered as a donor.');
        $this->table(['Field', 'Value'], [
            ['Email', $donor['email']],
            ['Phone', $donor['phone']],
            ['Blood type', $donor['donor_profile']['blood_type'] ?? 'Unknown'],
            ['Can sign in', 'Yes'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Gather the registration fields, asking for whatever is required and missing.
     *
     * Returns null when the two password entries disagree.
     *
     * @return array<string, mixed>|null
     */
    private function collectPayload(): ?array
    {
        $bloodType = $this->optionOrNull('blood-type');
        $validIdType = $this->optionOrNull('valid-id-type');

        $payload = [
            'first_name' => $this->optionOrAsk('first-name', 'First name'),
            'last_name' => $this->optionOrAsk('last-name', 'Last name'),
            'email' => $this->emailOrAsk('email', 'Email'),
            'phone' => $this->phoneOrAsk('phone', 'Mobile number'),
            'gender' => Str::lower($this->optionOrAsk('gender', 'Gender (male, female, other or prefer_not_to_say)')),
            'birth_date' => $this->optionOrAsk('birth-date', 'Birth date (YYYY-MM-DD)'),
            'address' => $this->optionOrAsk('address', 'Address'),
            'blood_type' => $bloodType === null ? null : Str::upper($bloodType),
            'valid_id_type' => $validIdType === null ? null : Str::lower($validIdType),
            // Normalised the way RegisterDonorRequest does before validating, so
            // the unique rule compares like for like.
            'valid_id_number' => AccountIdentity::normalizeValidIdNumber($this->optionOrNull('valid-id-number')),
        ];

        $password = $this->passwordOrAsk();

        if ($password === null) {
            return null;
        }

        return [
            ...$payload,
            'password' => $password,
            // The prompt already asked twice, and an operator who passed
            // --password meant it. The rule stays in force for the HTTP path,
            // so it is satisfied here rather than stripped.
            'password_confirmation' => $password,
            // Recorded as terms_accepted_at, as every registration is. The
            // operator creating the account is the one confirming it, the same
            // way admin:create stamps it for an administrator.
            'terms_accepted' => true,
        ];
    }
}
