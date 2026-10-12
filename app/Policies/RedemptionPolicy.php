<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class RedemptionPolicy
{
    /**
     * Approve or reject a redemption held by Redemption Fraud Detection.
     * Judges can look but never decide.
     */
    public function review(User $user): bool
    {
        return $user->hasRole(Role::BidAdmin, Role::PlatformAdmin);
    }
}
