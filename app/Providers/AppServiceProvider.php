<?php

namespace App\Providers;

use App\Models\User;
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
     * Define one gate per department ability so routes can guard with `can:`.
     *
     * Registered as explicit gates rather than through Gate::before(), which
     * would return true ahead of every policy in the application and hand a
     * supervisor ownership of every donor's appointment. A supervisor instead
     * earns each ability by holding it in DepartmentPermissions.
     */
    private function registerDepartmentGates(): void
    {
        foreach (DepartmentPermissions::all() as $ability) {
            Gate::define($ability, static function (User $user) use ($ability): bool {
                return in_array($ability, $user->abilities(), true);
            });
        }
    }
}
