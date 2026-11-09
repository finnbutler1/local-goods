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

    /**
     * Reviewers can edit their own feedback, never anyone else's. Everyone
     * using the public link shares one account, so "their own" means nothing
     * there: it can send feedback but never edit it.
     */
    public function update(User $user, Feedback $feedback): bool
    {
        return ! $user->isRevoked() && ! $user->isPublicReviewer() && $feedback->user_id === $user->id;
    }
}
