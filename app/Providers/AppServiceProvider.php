<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AdminPrivileges;
use App\Support\DepartmentPermissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDepartmentGates();
    }

    /**
     * Define one gate per ability so routes can guard with `can:`.
     *
     * Covers both catalogues: the blood-centre department matrix and the
     * platform-admin privileges. Both resolve through User::abilities(), which
     * merges them, so a single loop over the union is enough.
     *
     * The two key spaces cannot collide — AdminPrivileges prefixes every key
     * `admin.` — which matters here because a duplicate name would mean the
     * second Gate::define silently replaced the first.
     *
     * Registered as explicit gates rather than through Gate::before(), which
     * would return true ahead of every policy in the application and hand a
     * supervisor ownership of every donor's appointment. A supervisor instead
     * earns each ability by holding it in DepartmentPermissions.
     */
    private function registerDepartmentGates(): void
    {
        $abilities = [...DepartmentPermissions::all(), ...AdminPrivileges::all()];

        foreach ($abilities as $ability) {
            Gate::define($ability, static function (User $user) use ($ability): bool {
                return in_array($ability, $user->abilities(), true);
            });
        }
    }
}
