<?php

namespace App\Support;

/**
 * Whether "Forgot password?" can email a reset link. Production has no mail
 * service yet, so with the "log" mailer (or none) the link would only land in
 * the server log: the login page says to contact the admin instead.
 */
class PasswordResets
{
    public static function byEmail(): bool
    {
        return ! in_array(config('mail.default'), [null, '', 'log'], true);
    }
}
