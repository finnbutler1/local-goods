<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\DemoLogins;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Change the signed-in user's own password. Needs their current one.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        // A shared demo login: one judge changing it would lock out the rest.
        if (DemoLogins::isSharedLogin($user)) {
            throw ValidationException::withMessages(['current_password' => DemoLogins::PASSWORD_LOCKED])->errorBag('updatePassword');
        }

        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $input['password']])->save(); // hashed by the model cast
    }
}
