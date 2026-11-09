<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves the four demo logins from their previous addresses to the ones in
 * config/demo.php (ADR 0040). A dry run unless --apply is given. Writes the
 * email column and nothing else, on those four rows only. Judges' magic links
 * are compared before and after, and any difference undoes the whole change.
 */
class UpdateDemoEmails extends Command
{
    private const CHANGE = 'change';

    private const DONE = 'done';

    private const NOT_FOUND = 'not_found';

    private const TAKEN = 'taken';

    protected $signature = 'demo:update-emails {--apply : Write the change (without it, nothing is written)}';

    protected $description = 'Move the four demo logins to their new addresses (dry run unless --apply); never touches judges or the platform admin';

    public function handle(): int
    {
        return $this->option('apply') ? $this->apply() : $this->dryRun();
    }

    private function dryRun(): int
    {
        $before = $this->linkCheck();
        $this->showLinkCheck('before', $before);
        $plan = $this->plan();
        $this->showPlan($plan, 'Would change');
        $this->showSkipped();
        $after = $this->linkCheck();
        $this->showLinkCheck('after', $after);

        if ($after !== $before) {
            $this->error('FAILED: the reviewer links changed while this was running. Nothing was written.');

            return self::FAILURE;
        }

        $this->info('Dry run: nothing was written. Add --apply to make the change.');

        return self::SUCCESS;
    }

    private function apply(): int
    {
        $linksChanged = new class extends RuntimeException {};

        try {
            $plan = DB::transaction(function () use ($linksChanged) {
                // Locking reads, so MySQL compares the latest rows and New link can't land half way through.
                $before = $this->linkCheck(lock: true);
                $this->showLinkCheck('before', $before);
                $plan = $this->plan(lock: true);

                foreach ($plan as $row) {
                    if ($row['status'] === self::CHANGE) {
                        $row['user']->timestamps = false; // email only, not even updated_at
                        $row['user']->forceFill(['email' => $row['new']])->save();
                    }
                }

                $after = $this->linkCheck(lock: true);
                $this->showLinkCheck('after', $after);

                if ($after !== $before) {
                    throw $linksChanged;
                }

                return $plan;
            });
        } catch (RuntimeException $e) {
            if ($e !== $linksChanged) {
                throw $e;
            }

            $this->error('FAILED: the reviewer links differ after the change. Rolled back; nothing was written.');

            return self::FAILURE;
        }

        $this->showPlan($plan, 'Changed');
        $this->showSkipped();
        $changed = count(array_filter($plan, fn (array $row) => $row['status'] === self::CHANGE));
        $this->info($changed === 0 ? 'Nothing to change.' : "Done: {$changed} demo login(s) moved.");

        return self::SUCCESS;
    }

    /**
     * What to do for each role in config demo.previous_emails.
     *
     * @return list<array{role: string, status: string, user: ?User, previous: string, new: string}>
     */
    private function plan(bool $lock = false): array
    {
        $plan = [];

        foreach (config('demo.previous_emails') as $role => $previous) {
            $new = config("demo.accounts.$role.email");

            $user = User::where('role', $role)
                ->whereNotIn('role', [Role::Judge->value, Role::PlatformAdmin->value])
                ->where('email', $previous)
                ->when($lock, fn ($q) => $q->lockForUpdate())
                ->first();
            $user = $user?->email === $previous ? $user : null; // MySQL's comparison ignores case

            $holder = User::where('email', $new)->when($lock, fn ($q) => $q->lockForUpdate())->first();

            $status = match (true) {
                $holder !== null && ($user !== null || $holder->role->value !== $role) => self::TAKEN,
                $holder !== null => self::DONE,
                $user !== null => self::CHANGE,
                default => self::NOT_FOUND,
            };

            $plan[] = compact('role', 'status', 'user', 'previous', 'new');
        }

        return $plan;
    }

    /** @param list<array{role: string, status: string, user: ?User, previous: string, new: string}> $plan */
    private function showPlan(array $plan, string $verb): void
    {
        foreach ($plan as $row) {
            match ($row['status']) {
                self::CHANGE => $this->line("{$verb} {$row['role']}: {$row['previous']} -> {$row['new']}"),
                self::DONE => $this->line("Already done {$row['role']}: {$row['new']}"),
                self::TAKEN => $this->warn("Left alone {$row['role']}: {$row['new']} is already used by another account."),
                default => $this->warn("Left alone {$row['role']}: no {$row['role']} login has {$row['previous']}."),
            };
        }
    }

    /** Every judge, reviewer and platform admin: this command never changes them. */
    private function showSkipped(): void
    {
        User::whereIn('role', [Role::Judge->value, Role::PlatformAdmin->value])->orderBy('id')->get()
            ->each(fn (User $user) => $this->line(
                "Skipped {$user->role->value}: #{$user->id} {$user->adminLabel()} (".($user->adminEmail() ?? 'email hidden').')'
            ));
    }

    /**
     * The state of every reviewer link, without the secret: the check is a
     * short fingerprint that can't be turned back into the stored hash.
     *
     * @return array{judges: int, live: int, rows: array<int, array{label: string, link: bool, revoked: bool, check: string}>}
     */
    private function linkCheck(bool $lock = false): array
    {
        $judges = User::where('role', Role::Judge->value)->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();

        return [
            'judges' => $judges->count(),
            'live' => $judges->filter(fn (User $u) => $u->invite_token_hash !== null && $u->revoked_at === null)->count(),
            'rows' => $judges->mapWithKeys(fn (User $u) => [$u->id => [
                'label' => $u->adminLabel(),
                'link' => $u->invite_token_hash !== null,
                'revoked' => $u->revoked_at !== null,
                'check' => $u->invite_token_hash === null ? '-' : substr(hash('sha256', 'check|'.$u->invite_token_hash), 0, 8),
            ]])->all(),
        ];
    }

    /** @param array{judges: int, live: int, rows: array<int, array{label: string, link: bool, revoked: bool, check: string}>} $check */
    private function showLinkCheck(string $when, array $check): void
    {
        $this->line("Reviewer links {$when}: {$check['judges']} judge rows, {$check['live']} live links");

        foreach ($check['rows'] as $id => $row) {
            $this->line("  #{$id} {$row['label']}: link ".($row['link'] ? 'set' : 'none').', revoked '.($row['revoked'] ? 'yes' : 'no').", check {$row['check']}");
        }
    }
}
