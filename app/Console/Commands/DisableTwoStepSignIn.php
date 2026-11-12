<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DemoLogins;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Emergency recovery: turns off two-step sign-in for one staff account whose
 * owner lost both their phone and their recovery codes. A dry run unless
 * --apply is given (the explicit confirmation, run after reading the dry
 * run). Clears only the three two-factor columns on that one row: the
 * password and everything else stay as they are, and the person signs in with
 * their password again. Refuses unknown emails, the shared demo logins and
 * judges. Logs that it happened (user id and role, never the key, codes or
 * email). On the live instance: scripts/artisan-as-webapp.sh.
 */
class DisableTwoStepSignIn extends Command
{
    public const LOG_MESSAGE = 'Two-step sign-in turned off by command';

    protected $signature = 'auth:disable-two-step
        {email : The email of the account to turn it off for}
        {--apply : Turn it off (without it, nothing is written)}';

    protected $description = 'Turn off two-step sign-in for one staff account (dry run unless --apply); refuses demo logins and judges';

    public function handle(): int
    {
        $user = User::where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if (! $user) {
            $this->error('Refused: no account has that email. Nothing was changed.');

            return self::FAILURE;
        }

        if (DemoLogins::isSharedLogin($user) || $user->isReadOnly()) {
            $this->error('Refused: shared demo logins and judges never have two-step sign-in. Nothing was changed.');

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null && $user->two_factor_confirmed_at === null) {
            $this->error('Refused: two-step sign-in is not on for that account. Nothing was changed.');

            return self::FAILURE;
        }

        $state = $user->two_factor_confirmed_at
            ? 'on since '.$user->two_factor_confirmed_at->copy()->timezone('America/New_York')->format('Y-m-d H:i T')
            : 'set up but never finished';
        $who = "account #{$user->id} ({$user->role->label()}), two-step sign-in {$state}";

        if (! $this->option('apply')) {
            $this->line("Would turn off two-step sign-in for {$who}.");
            $this->line('Dry run: nothing was changed. Run it again with --apply to turn it off.');

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        Log::warning(self::LOG_MESSAGE, ['user_id' => $user->id, 'role' => $user->role->value]);

        $this->info("Turned off two-step sign-in for {$who}.");
        $this->line('They can log in with their password now, and set it up again from Your account.');

        return self::SUCCESS;
    }
}
