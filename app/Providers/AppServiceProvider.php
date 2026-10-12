<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
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
        // Who may change what. Judges pass none of these; BlockJudgeWrites
        // also stops them at the door, so both layers must agree.
        Gate::define('reset-demo', fn (User $user) => $user->hasRole(Role::BidAdmin, Role::PlatformAdmin));
        Gate::define('redeem', fn (User $user) => $user->hasRole(Role::Employee));
        Gate::define('manage-reviewers', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
        Gate::define('manage-feedback', fn (User $user) => $user->hasRole(Role::PlatformAdmin));
    }
}
