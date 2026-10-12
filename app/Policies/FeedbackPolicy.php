<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\User;

class FeedbackPolicy
{
    /** Anyone signed in can leave feedback, judges included. */
    public function create(User $user): bool
    {
        return ! $user->isRevoked();
    }

    /** Reviewers can edit their own feedback, never anyone else's. */
    public function update(User $user, Feedback $feedback): bool
    {
        return ! $user->isRevoked() && $feedback->user_id === $user->id;
    }
}
