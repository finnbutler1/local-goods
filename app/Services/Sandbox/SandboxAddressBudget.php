<?php

namespace App\Services\Sandbox;

/**
 * How many DC address lookups sandboxes may make (ADR 0045 addendum, "A new
 * shop joins"): a cap per sandbox and a cap per day across all of them,
 * counted exactly like the AI calls (the sandbox's own cache, and a small
 * locked file in the sandbox folder). Neither touches the main database.
 */
class SandboxAddressBudget extends SandboxAiBudget
{
    public const SANDBOX_KEY = 'sandbox.address_lookups';

    protected const DAY_FILE_PREFIX = 'address-lookups';

    protected function perSandbox(): int
    {
        return (int) config('sandbox.address_lookups_per_sandbox');
    }

    protected function perDay(): int
    {
        return (int) config('sandbox.address_lookups_per_day');
    }
}
