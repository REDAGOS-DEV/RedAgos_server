<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PromptsForAccountDetails;
use App\Enums\AccountStatus;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Service\UserService;
use App\Support\AdminPrivileges;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create a platform administrator from the console.
 *
 * This exists because the API cannot bootstrap the first one. StoreUserRequest
 * refuses `is_super_admin` unless the *caller* already holds it, which is the
 * escalation guard keeping a scoped admin from promoting itself — correct, and
 * it also means a fresh database has no way to produce its first unrestricted
 * account over HTTP. A console operator already holds the database credentials,
 * so nothing is weakened by letting them do it here.
 *
 * Creation goes through UserService rather than User::create so the account is
 * built the way the admin portal builds one: uuid assigned, roles synced, all
 * inside a single transaction.
 *
 * The email is marked verified on the way out. That is not a convenience —
 * AuthService::login refuses an unverified address outright and
 * `email_verified_at` is not fillable, so an admin created without this step
 * would exist and be unable to sign in.
 */
class CreateAdminUser extends Command
{
    use PromptsForAccountDetails;

    protected $signature = 'admin:create
                            {--first-name= : Given name}
                            {--last-name= : Surname}
                            {--email= : Sign-in address, also the account contact}
                            {--username= : Unique handle}
                            {--password= : Leave unset to be prompted; an argument is visible in shell history}
                            {--scoped=* : Grant only these privileges instead of unrestricted access}';

    protected $description = 'Create a platform administrator account';

    public function __construct(private readonly UserService $userService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $privileges = $this->option('scoped');

        if (($unknown = array_diff($privileges, AdminPrivileges::all())) !== []) {
            $this->error('Unknown privilege: '.implode(', ', $unknown));
            $this->line('Available: '.implode(', ', AdminPrivileges::all()));

            return self::FAILURE;
        }

        $attributes = $this->collectAttributes();

        if ($attributes === null) {
            return self::FAILURE;
        }

        $validator = Validator::make($attributes, [
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'username' => ['required', 'string', 'max:150', 'unique:users,username'],
            'password' => ['required', Password::min(8)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($attributes, $privileges): array {
            // On the empty database this command exists for, roles may never
            // have been seeded. UserService resolves the role by name and syncs
            // whatever it finds, so without this the account comes out holding
            // no role at all and is turned away at the sign-in portal.
            Role::firstOrCreate(['name' => RoleName::Admin->value]);

            $account = $this->userService->createUser([
                ...$attributes,
                'account_status' => AccountStatus::Active->value,
                'activated_at' => now(),
                'terms_accepted_at' => now(),
                'is_super_admin' => $privileges === [],
                'admin_privileges' => $privileges === [] ? null : array_values($privileges),
                'roles' => [RoleName::Admin->value],
            ]);

            User::query()->where('uuid', $account['uuid'])->sole()->markEmailAsVerified();

            return $account;
        });

        $this->info('Administrator created.');
        $this->table(['Field', 'Value'], [
            ['Email', $created['email']],
            ['Username', $created['username']],
            ['Access', $created['is_super_admin']
                ? 'Unrestricted'
                : implode(', ', $created['admin_privileges'])],
        ]);

        return self::SUCCESS;
    }

    /**
     * Gather every field, asking for whatever was not passed as an option.
     *
     * Returns null when the two password entries disagree.
     *
     * @return array<string, string>|null
     */
    private function collectAttributes(): ?array
    {
        $attributes = [
            'first_name' => $this->optionOrAsk('first-name', 'First name'),
            'last_name' => $this->optionOrAsk('last-name', 'Last name'),
            'email' => $this->emailOrAsk('email', 'Email'),
            'username' => $this->optionOrAsk('username', 'Username'),
        ];

        $password = $this->passwordOrAsk();

        return $password === null ? null : [...$attributes, 'password' => $password];
    }
}
