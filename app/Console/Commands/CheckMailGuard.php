<?php

namespace App\Console\Commands;

use App\Support\DemoMailGuard;
use Illuminate\Console\Command;

/** Shows the demo mail guard is on, without sending anything. Reads only. */
class CheckMailGuard extends Command
{
    /** One demo address the guard must refuse, one real-world address it must let through. */
    public const SAMPLES = [
        'jordan.rivera@meridianpolicygroup.com' => true,
        'someone@cua.edu' => false,
    ];

    protected $signature = 'demo:check-mail-guard';

    protected $description = 'Show which domains the demo mail guard refuses and check two sample addresses (sends no email, writes nothing)';

    public function handle(): int
    {
        $this->line('Guarded domains: '.(implode(', ', DemoMailGuard::domains()) ?: 'none'));

        $ok = true;
        foreach (self::SAMPLES as $address => $expected) {
            $blocked = DemoMailGuard::isBlocked($address);
            $ok = $ok && $blocked === $expected;
            $this->line($address.': '.($blocked ? 'blocked' : 'not blocked').($blocked === $expected ? '' : ' (WRONG)'));
        }

        $this->line($ok ? 'The mail guard is on.' : 'FAILED: the mail guard is not working as expected.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
