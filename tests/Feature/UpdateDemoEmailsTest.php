<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use App\Services\ReviewerLinks;
use App\Support\DemoMailGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateDemoEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'test-only-password';

    /** @var array<string, string> */
    private array $links = [];

    /** @var list<int> */
    private array $demoIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewers.judging_ends_at' => '2026-11-30 23:59']);
        $this->travelTo('2026-11-08 12:00');

        $reviewers = app(ReviewerLinks::class);
        foreach ([
            'named' => ['Casey Morgan', 'casey@cua.edu', ReviewerType::ContestJudge],
            'public' => ['Public judge access', config('demo.accounts.judge.email'), ReviewerType::Public],
            'personal' => ['Robin Hartley', 'robin.hartley@example.net', ReviewerType::PersonalContact],
            'revoked' => ['Drew Ellis', 'drew.ellis@example.net', ReviewerType::Bid],
        ] as $key => [$name, $email, $type]) {
            [, $link] = $reviewers->invite($name, $email, $type);
            $this->links[$key] = Str::after($link, '#');
        }
        User::where('email', 'drew.ellis@example.net')->firstOrFail()->forceFill(['revoked_at' => now(), 'invite_token_hash' => null])->save();

        User::factory()->role(Role::PlatformAdmin)->create(['email' => config('demo.accounts.platform_admin.email')]);
        User::factory()->role(Role::Employee)->create(['email' => 'another.employee@example.net']);
        User::factory()->role(Role::BidAdmin)->create(['email' => 'another.admin@example.net']);

        $this->travelTo('2026-11-08 12:05'); // a saved updated_at would now differ

        foreach (config('demo.previous_emails') as $role => $email) {
            $this->demoIds[] = User::factory()->role(Role::from($role))->create([
                'name' => config("demo.accounts.$role.name"), 'email' => $email,
                'password' => self::PASSWORD, 'remember_token' => 'remember-'.$role,
            ])->id;
        }

        $this->travelTo('2026-11-08 12:10');
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return DB::table('users')->orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
    }

    private function setPasswords(): void
    {
        foreach (array_keys(config('demo.previous_emails')) as $role) {
            config(["demo.accounts.$role.password" => self::PASSWORD]);
        }
        config(['demo.accounts.platform_admin.password' => null]);
    }

    private function newEmail(string $role): string
    {
        return config("demo.accounts.$role.email");
    }

    public function test_the_new_addresses_are_all_on_guarded_domains(): void
    {
        $this->assertSame(['bid_admin', 'employer_admin', 'employee', 'merchant'], array_keys(config('demo.previous_emails')));

        foreach (array_keys(config('demo.previous_emails')) as $role) {
            $this->assertTrue(DemoMailGuard::isBlocked($this->newEmail($role)), $role);
        }
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $before = $this->rows();

        $this->artisan('demo:update-emails')
            ->expectsOutputToContain('Would change employee: jordan.rivera@example.com -> jordan.rivera@meridianpolicygroup.com')
            ->expectsOutputToContain('nothing was written')
            ->assertSuccessful();

        $this->assertSame($before, $this->rows());
    }

    public function test_apply_changes_only_the_email_of_only_the_four(): void
    {
        $before = $this->rows();

        $this->artisan('demo:update-emails', ['--apply' => true])->assertSuccessful();

        $after = $this->rows();
        foreach ($before as $id => $row) {
            if (in_array($id, $this->demoIds, true)) {
                $role = $row['role'];
                $this->assertSame(array_replace($row, ['email' => $this->newEmail($role)]), $after[$id], $role);
            } else {
                $this->assertSame($row, $after[$id], 'An account outside the four changed');
            }
        }
    }

    public function test_a_judge_or_platform_admin_on_a_previous_address_is_not_moved(): void
    {
        User::where('email', 'sam.taylor@example.com')->delete();
        User::where('email', 'alex.morgan@example.com')->delete();
        app(ReviewerLinks::class)->invite('Casey Sample', 'sam.taylor@example.com', ReviewerType::ContestJudge);
        User::factory()->role(Role::PlatformAdmin)->create(['email' => 'alex.morgan@example.com']);
        $before = $this->rows();

        $this->artisan('demo:update-emails', ['--apply' => true])
            ->expectsOutputToContain('Left alone bid_admin')
            ->expectsOutputToContain('Left alone employer_admin')
            ->assertSuccessful();

        $after = $this->rows();
        foreach ($before as $id => $row) {
            if (in_array($row['email'], ['sam.taylor@example.com', 'alex.morgan@example.com'], true)) {
                $this->assertSame($row, $after[$id]);
            }
        }
        $this->assertFalse(User::where('email', $this->newEmail('bid_admin'))->exists());
    }

    public function test_every_judge_and_the_platform_admin_are_shown_as_skipped_without_secrets(): void
    {
        Artisan::call('demo:update-emails', ['--apply' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Skipped judge: ', $output);
        $this->assertStringContainsString('Casey Morgan (casey@cua.edu)', $output);
        $this->assertStringContainsString('R.H. (email hidden)', $output);
        $this->assertStringContainsString('Skipped platform_admin: ', $output);
        $this->assertStringNotContainsString('Robin Hartley', $output);
        $this->assertStringNotContainsString('robin.hartley', $output);
        $this->assertStringContainsString('4 judge rows, 3 live links', $output);

        foreach (User::whereNotNull('invite_token_hash')->pluck('invite_token_hash') as $hash) {
            $this->assertStringNotContainsString($hash, $output);
            $this->assertStringContainsString(substr(hash('sha256', 'check|'.$hash), 0, 8), $output);
        }
    }

    public function test_a_reviewer_link_made_before_apply_still_signs_in(): void
    {
        $this->artisan('demo:update-emails', ['--apply' => true])->assertSuccessful();

        $this->post('/judge-access', ['link' => $this->links['named']])->assertRedirect('/judge');

        $this->assertAuthenticatedAs(User::where('email', 'casey@cua.edu')->firstOrFail());
    }

    public function test_demo_logins_work_at_the_new_addresses_and_not_the_old(): void
    {
        $this->artisan('demo:update-emails', ['--apply' => true])->assertSuccessful();

        foreach (config('demo.previous_emails') as $role => $old) {
            $this->post('/login', ['email' => $old, 'password' => self::PASSWORD]);
            $this->assertGuest();

            $this->post('/login', ['email' => $this->newEmail($role), 'password' => self::PASSWORD]);
            $this->assertAuthenticatedAs(User::where('email', $this->newEmail($role))->firstOrFail());

            auth()->logout();
            $this->flushSession();
        }
    }

    public function test_running_twice_is_a_no_op(): void
    {
        $this->artisan('demo:update-emails', ['--apply' => true])->assertSuccessful();
        $before = $this->rows();

        $this->artisan('demo:update-emails', ['--apply' => true])
            ->expectsOutputToContain('Already done merchant')
            ->expectsOutputToContain('Nothing to change.')
            ->assertSuccessful();

        $this->assertSame($before, $this->rows());
    }

    public function test_wallet_and_business_links_stay(): void
    {
        (new DatabaseSeeder)->run();
        $jordan = User::where('email', 'jordan.rivera@example.com')->firstOrFail();
        $priya = User::where('email', 'priya.natarajan@example.com')->firstOrFail();
        $employee = Employee::orderBy('id')->firstOrFail();
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
        $employee->update(['user_id' => $jordan->id]);
        $merchant->update(['user_id' => $priya->id]);

        $this->artisan('demo:update-emails', ['--apply' => true])->assertSuccessful();

        $this->assertSame($jordan->id, $employee->fresh()->user_id);
        $this->assertSame($priya->id, $merchant->fresh()->user_id);
        $this->assertSame($this->newEmail('employee'), $employee->fresh()->user->email);
    }

    public function test_before_the_update_the_seeder_skips_and_creates_no_duplicates(): void
    {
        $this->setPasswords();
        $before = $this->rows();

        $this->artisan('demo:seed-accounts')
            ->expectsOutputToContain('Skipped bid_admin: still on its previous address; run demo:update-emails.')
            ->expectsOutputToContain('Skipped merchant: still on its previous address; run demo:update-emails.')
            ->assertSuccessful();

        $this->assertSame(array_keys($before), array_keys($this->rows()));
        foreach ($this->demoIds as $id) {
            $this->assertSame($before[$id], $this->rows()[$id]);
        }
    }

    public function test_after_the_update_the_seeder_finds_them_by_their_new_address(): void
    {
        $this->setPasswords();
        $this->artisan('demo:update-emails', ['--apply' => true])->assertSuccessful();
        $count = User::count();

        config(['demo.accounts.employee.password' => 'rotated-password']);
        $this->artisan('demo:seed-accounts')
            ->doesntExpectOutputToContain('run demo:update-emails')
            ->assertSuccessful();

        $this->assertSame($count, User::count());
        $this->assertTrue(Hash::check('rotated-password', User::where('email', $this->newEmail('employee'))->value('password')));
    }

    public function test_a_new_address_already_in_use_leaves_that_login_alone(): void
    {
        $other = User::factory()->role(Role::Employee)->create(['email' => $this->newEmail('merchant')]);

        $this->artisan('demo:update-emails', ['--apply' => true])
            ->expectsOutputToContain('Left alone merchant')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'priya.natarajan@example.com')->exists());
        $this->assertSame(Role::Employee, $other->fresh()->role);
        $this->assertTrue(User::where('email', $this->newEmail('employee'))->exists());
    }

    public function test_a_reviewer_link_that_changes_during_apply_rolls_everything_back(): void
    {
        $before = $this->rows();
        $judgeId = User::where('email', 'casey@cua.edu')->value('id');

        User::updated(function () use ($judgeId) {
            DB::table('users')->where('id', $judgeId)->update(['invite_token_hash' => hash('sha256', 'changed')]);
        });

        $this->artisan('demo:update-emails', ['--apply' => true])
            ->expectsOutputToContain('Rolled back')
            ->assertExitCode(1);

        $this->assertSame($before, $this->rows());
    }
}
