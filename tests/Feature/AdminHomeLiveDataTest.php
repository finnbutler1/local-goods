<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\Correction;
use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\SolvencyCheckRun;
use App\Models\User;
use App\Services\DemoActivity\ActivityGuard;
use App\Services\ReviewerLinks;
use App\Services\Solvency\SolvencyCheck;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The platform admin Home with data the live site has and the seed doesn't.
 * Every case must render: this page is where the admin goes when something
 * else is wrong.
 */
class AdminHomeLiveDataTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_SET = 'Not set: add PUBLIC_JUDGE_URL in the Beanstalk environment properties.';

    private User $public;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewers.judging_ends_at' => now()->addMonth()->toDateTimeString()]);
        $this->seed(DatabaseSeeder::class);
        DemoAccountSeeder::seedPublicReviewer(config('demo.accounts.judge'));
        $this->public = User::where('reviewer_type', ReviewerType::Public)->sole();
        $this->signIn(Role::PlatformAdmin);
    }

    private function setLink(?User $for = null): string
    {
        $url = 'https://uselocalgoods.com/judge-access#'.Str::after(app(ReviewerLinks::class)->newLink($for ?? $this->public), '#');
        config(['reviewers.public_judge_url' => $url]);

        return $url;
    }

    private function reviewer(ReviewerType $type): User
    {
        $user = User::factory()->role(Role::Judge)->create(['name' => 'Avery Example']);
        $user->forceFill(['reviewer_type' => $type])->save();

        return $user;
    }

    private function assertHomeSaysNotSet(): void
    {
        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee(self::NOT_SET);
    }

    /** @param array<string, mixed> $reconciliation */
    private function dayWithReconciliation(array $reconciliation): void
    {
        DemoActivityDay::create([
            'date' => CarbonImmutable::now(ActivityGuard::TIMEZONE)->startOfDay(),
            'seed' => 1,
            'plan' => [],
            'last_reconciliation' => $reconciliation,
        ]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function recordedReconciliations(): array
    {
        return [
            'matches' => [['at' => '2026-11-09T14:40:00+00:00', 'state' => 'abc', 'matches' => true, 'waiting_for_webhooks' => false], 'Matches'],
            'mismatch' => [['at' => '2026-11-09T14:40:00+00:00', 'state' => null, 'matches' => false, 'waiting_for_webhooks' => false], 'Mismatch'],
            'waiting for webhooks' => [['at' => '2026-11-09T14:40:00+00:00', 'state' => null, 'matches' => false, 'waiting_for_webhooks' => true], 'Mismatch'],
        ];
    }

    /** @param array<string, mixed> $reconciliation */
    #[DataProvider('recordedReconciliations')]
    public function test_home_renders_once_the_generator_has_recorded_a_reconciliation_today(array $reconciliation, string $word): void
    {
        $this->dayWithReconciliation($reconciliation);

        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee($word);
    }

    public function test_the_demo_activity_page_still_gets_the_whole_stored_check(): void
    {
        $this->dayWithReconciliation(['at' => '2026-11-09T14:40:00+00:00', 'state' => 'abc', 'matches' => true, 'waiting_for_webhooks' => false]);

        $this->withoutExceptionHandling();
        $this->get(route('bid-admin.demo-activity'))->assertOk();
    }

    public function test_home_renders_a_halted_day_with_failed_actions_while_the_generator_is_on(): void
    {
        config(['demo_activity.enabled' => true, 'demo_activity.ends_on' => now()->addMonth()->toDateString()]);
        $day = DemoActivityDay::create(['date' => CarbonImmutable::now(ActivityGuard::TIMEZONE)->startOfDay(), 'seed' => 1, 'plan' => [], 'halted_reason' => 'Synthetic halt.']);
        DemoActivityAction::forceCreate(['day_id' => $day->id, 'slot' => 'a', 'kind' => DemoActivityAction::KIND_REDEMPTION, 'status' => DemoActivityAction::STATUS_FAILED]);

        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('1 failed')->assertSee('The day was stopped.');
    }

    public function test_home_renders_after_the_generator_has_ended(): void
    {
        config(['demo_activity.enabled' => true, 'demo_activity.ends_on' => now()->subMonth()->toDateString()]);

        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Ended');
    }

    public function test_home_renders_every_correction_type_and_status(): void
    {
        $id = Correction::orderBy('id')->value('id');
        $this->assertNotNull($id, 'The seed makes corrections.');
        $this->withoutExceptionHandling();

        foreach (['redemption_refund', 'merchant_adjustment', 'employer_refund'] as $type) {
            foreach (['requested', 'approved', 'processing', 'completed', 'failed', 'rejected', 'closed'] as $status) {
                DB::table('corrections')->where('id', $id)->update(['type' => $type, 'status' => $status, 'updated_at' => now()->addDay()]);
                $this->get(route('admin.dashboard'))->assertOk();
            }
        }
    }

    public function test_home_renders_every_solvency_trigger(): void
    {
        $this->withoutExceptionHandling();

        foreach (SolvencyCheckRun::TRIGGERS as $trigger) {
            SolvencyCheckRun::record(app(SolvencyCheck::class)->history(), $trigger);
            $this->get(route('admin.dashboard'))->assertOk();
        }
    }

    public function test_home_renders_failed_and_sent_sweeps(): void
    {
        $this->withoutExceptionHandling();

        foreach (['failed', 'sent', 'pending'] as $status) {
            DB::table('operating_sweeps')->insert(['amount_cents' => 16, 'channel' => 'simulated', 'status' => $status, 'reason' => 'Synthetic.', 'reviewed_against_cents' => 16, 'decided_by_user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('was refused')->assertSee('was recorded');
    }

    public function test_home_only_counts_many_feedback_rows_from_any_kind_of_reviewer(): void
    {
        $revoked = $this->reviewer(ReviewerType::ContestJudge);
        $revoked->forceFill(['revoked_at' => now(), 'invite_token_hash' => null])->save();
        $contact = $this->reviewer(ReviewerType::PersonalContact);
        $row = ['page_url' => '/judge', 'category' => 'ux_design', 'rating' => 3, 'severity' => 'should_fix', 'comment' => 'Synthetic.'];

        foreach (range(1, 500) as $n) {
            ($n % 2 ? $revoked : $contact)->feedback()->create($row);
        }

        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('500 new items');
    }

    public function test_a_working_link_shows_with_the_copy_button_and_no_noindex_for_an_admin(): void
    {
        $url = $this->setLink();

        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee('value="'.$url.'"', false)
            ->assertSee('data-copy="#public-judge-link"', false)
            ->assertDontSee('noindex');
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableLinkSettings(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'not a link' => ['nonsense'],
            'other host' => ['https://example.com/judge-access#1.'.str_repeat('a', 48)],
            'no such user' => ['https://uselocalgoods.com/judge-access#9999999999.'.str_repeat('a', 48)],
        ];
    }

    #[DataProvider('unusableLinkSettings')]
    public function test_an_unusable_setting_says_not_set(mixed $value): void
    {
        config(['reviewers.public_judge_url' => $value]);

        $this->assertHomeSaysNotSet();
    }

    public function test_a_revoked_public_row_says_not_set(): void
    {
        $this->setLink();
        app(ReviewerLinks::class)->revoke($this->public);

        $this->assertHomeSaysNotSet();
    }

    public function test_an_old_link_after_a_new_one_says_not_set(): void
    {
        $this->setLink();
        app(ReviewerLinks::class)->newLink($this->public);

        $this->assertHomeSaysNotSet();
    }

    public function test_a_deleted_public_row_says_not_set(): void
    {
        $this->setLink();
        DB::table('users')->where('id', $this->public->id)->delete();

        $this->assertHomeSaysNotSet();
    }

    public function test_a_named_reviewers_or_personal_contacts_link_says_not_set(): void
    {
        $this->setLink($this->reviewer(ReviewerType::ContestJudge));
        $this->assertHomeSaysNotSet();

        $this->setLink($this->reviewer(ReviewerType::PersonalContact));
        $this->assertHomeSaysNotSet();
    }

    public function test_a_link_pointing_at_a_staff_account_says_not_set(): void
    {
        $staff = User::where('role', Role::PlatformAdmin)->orderBy('id')->firstOrFail();
        config(['reviewers.public_judge_url' => 'https://uselocalgoods.com/judge-access#'.$staff->id.'.'.str_repeat('a', 48)]);

        $this->assertHomeSaysNotSet();
    }

    public function test_after_judging_ends_the_link_says_not_set(): void
    {
        $this->setLink();
        config(['reviewers.judging_ends_at' => now()->subDay()->toDateTimeString()]);

        $this->assertHomeSaysNotSet();
    }

    public function test_an_unreadable_end_date_does_not_break_home(): void
    {
        $this->setLink();
        config(['reviewers.judging_ends_at' => 'not a date']);

        $this->assertHomeSaysNotSet();
    }

    public function test_an_unknown_reviewer_type_on_the_links_row_does_not_break_home(): void
    {
        $this->setLink();
        DB::table('users')->where('id', $this->public->id)->update(['reviewer_type' => 'retired_type']);

        $this->assertHomeSaysNotSet();
    }

    public function test_an_unknown_role_on_the_links_row_does_not_break_home(): void
    {
        $this->setLink();
        DB::table('users')->where('id', $this->public->id)->update(['role' => 'retired_role']);

        $this->assertHomeSaysNotSet();
    }

    public function test_blank_names_do_not_break_home(): void
    {
        $this->setLink();
        DB::table('users')->where('id', $this->public->id)->update(['name' => '']);
        $admin = User::where('role', Role::PlatformAdmin)->orderBy('id')->firstOrFail();
        $admin->forceFill(['name' => ''])->save();
        $this->actingAs($admin);

        $this->withoutExceptionHandling();
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_the_build_has_both_entries_the_layout_asks_for(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
            $this->assertArrayHasKey($entry, $manifest);
            $this->assertFileExists(public_path('build/'.$manifest[$entry]['file']));
        }
    }
}
