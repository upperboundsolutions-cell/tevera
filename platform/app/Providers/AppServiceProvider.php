<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
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
        Gate::define('manage-platform', fn (User $user) => $user->canAccessPlatform() && $user->role === Role::SuperAdmin);
        Gate::define('manage-fleet', fn (User $user) => $user->canAccessPlatform() && $user->role->managesFleet());
        Gate::define('manage-users', fn (User $user) => $user->canAccessPlatform() && in_array($user->role, [Role::SuperAdmin, Role::Admin], true));
        RateLimiter::for('auth-ip', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(3)->by('email:'.hash('sha256', strtolower((string) $request->input('email')))),
        ]);
        RateLimiter::for('traccar-check', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id));
    }
}
