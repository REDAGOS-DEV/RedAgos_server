<?php

namespace App\Console\Commands\Concerns;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Names the administrator a console-performed action is recorded against.
 *
 * The facility commands do from a terminal what a Super Admin does in the
 * portal, and both leave a trail — facilities.approved_by and the audit log —
 * that answers "who did this". A console session has no authenticated user, so
 * the operator supplies one. Writing null instead would make the one operation
 * with no browser session behind it the one operation nobody is accountable for.
 */
trait ResolvesActingAdministrator
{
    /**
     * Resolve the administrator whose id is recorded against this run.
     *
     * Defaults to the only administrator when there is exactly one, which is
     * the state a freshly bootstrapped database is in. Past that the operator
     * names one rather than the command guessing.
     */
    protected function resolveActingAdministrator(string $option = 'admin'): ?User
    {
        $email = trim((string) $this->option($option));
        $email = $email === '' ? null : Str::lower($email);

        $administrators = User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', RoleName::Admin->value))
            ->when($email !== null, fn (Builder $query) => $query->where('email', $email))
            // Two is all it takes to know the answer is ambiguous.
            ->limit(2)
            ->get();

        if ($administrators->isEmpty()) {
            $this->error($email === null
                ? 'No administrator exists to record this against. Run admin:create first.'
                : "No administrator holds the address {$email}.");

            return null;
        }

        if ($administrators->count() > 1) {
            $this->error("Several administrators exist. Name the one acting with --{$option}=<email>.");

            return null;
        }

        return $administrators->first();
    }
}
