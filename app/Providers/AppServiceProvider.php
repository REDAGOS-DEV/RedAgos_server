<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AdminPrivileges;
use App\Support\DepartmentPermissions;
use App\Support\HospitalPermissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerCounterRateLimiters();
    }

    /**
     * The Donation counter's own rate limits, each on its own counter.
     *
     * A plain `throttle:N,1` shares one per-user counter across every route
     * that uses it. The walk-in search runs as staff type, so on that shared
     * counter a busy minute of searching could refuse the onsite questionnaire
     * a donor has just spent thirty questions answering. Named limiters keep
     * the two budgets apart.
     */
    private function registerCounterRateLimiters(): void
    {
        $byStaff = static fn (Request $request): string => (string) ($request->user()?->id ?? $request->ip());

        // Several requests per name typed, a few names per walk-in.
        RateLimiter::for('counter-donor-search', static fn (Request $request): Limit => Limit::perMinute(120)->by($byStaff($request)));

        RateLimiter::for('counter-questionnaire', static fn (Request $request): Limit => Limit::perMinute(30)->by($byStaff($request)));
    }

    /**
     * Define one gate per ability so routes can guard with `can:`.
     *
     * Covers the three catalogues: the blood-centre department matrix, the
     * hospital blood bank roles and the platform-admin privileges. All resolve
     * through User::abilities(), which merges them, so a single loop over the
     * union is enough.
     *
     * The key spaces cannot collide — HospitalPermissions prefixes every key
     * `hospital.` and AdminPrivileges `admin.` — which matters here because a
     * duplicate name would mean the second Gate::define silently replaced the
     * first.
     *
     * Registered as explicit gates rather than through Gate::before(), which
     * would return true ahead of every policy in the application and hand a
     * privileged account ownership of every donor's appointment. Every account
     * instead earns each ability by holding it in DepartmentPermissions.
     */
    private function registerDepartmentGates(): void
    {
        $abilities = [...DepartmentPermissions::all(), ...HospitalPermissions::all(), ...AdminPrivileges::all()];

        foreach ($abilities as $ability) {
            Gate::define($ability, static function (User $user) use ($ability): bool {
                return in_array($ability, $user->abilities(), true);
            });
        }
    }
}
