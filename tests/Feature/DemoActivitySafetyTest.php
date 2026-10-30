<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\DemoActivityAction;
use App\Models\DemoActivityDay;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\DemoActivity\ActivityGenerator;
use App\Services\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The demo activity generator's safety rules (ADR 0032): each refusal, each
 * daily cap, the queue caps, stopping the day after failures or two
 * reconciliation mismatches, the people it never picks, the data it never
 * touches and the email it never sends.
 */
class DemoActivitySafetyTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    /** Tables the generator must never change, whatever happens. */
    private const HANDS_OFF = [
        'users', 'feedback', 'changelog_entries', 'merchant_review_decisions', 'merchant_applications', 'merchant_application_events',
        'adjudication_sets', 'adjudication_cases', 'adjudication_participants', 'adjudication_votes', 'adjudication_assignments',
        'partnerships', 'partnership_changes', 'merchant_fee_changes', 'districts', 'address_lookups',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startDemoActivity();
    }

    public static function refusals(): array
    {
        return [
            'switched off' => [fn () => config(['demo_activity.enabled' => false]), '2026-10-26 12:30', 'DEMO_ACTIVITY_ENABLED'],
            'no Stripe keys (simulated)' => [fn () => config(['services.stripe.key' => null, 'services.stripe.secret' => null]), '2026-10-26 12:30', 'test mode'],
            'live-looking keys' => [fn () => config(['services.stripe.key' => 'pk_live_not_a_real_key', 'services.stripe.secret' => 'sk_live_not_a_real_key']), '2026-10-26 12:30', 'test mode'],
            'after the end date' => [fn () => config(['demo_activity.ends_on' => '2026-10-25']), '2026-10-26 12:30', 'Ended'],
            'weekend' => [fn () => null, '2026-10-31 12:30', 'Weekend'],
            'before hours' => [fn () => null, '2026-10-26 06:55', 'Outside'],
            'after hours' => [fn () => null, '2026-10-26 19:00', 'Outside'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses_and_writes_nothing(\Closure $setUp, string $at, string $says): void
    {
        $setUp();
        $before = $this->counts();

        $lines = $this->tickAt($at);

        $this->assertStringContainsString($says, $lines[0]);
        $this->assertSame($before, $this->counts());
        $this->assertSame([], $this->stripe->calls);
        $this->assertSame([], $this->claude->calls);
    }

    public function test_it_is_off_unless_switched_on_and_no_deploy_file_switches_it_on(): void
    {
        $defaults = require config_path('demo_activity.php');
        $this->assertFalse($defaults['enabled']);
        $this->assertStringContainsString("env('DEMO_ACTIVITY_ENABLED', false)", File::get(config_path('demo_activity.php')));

        $deployFiles = collect([...File::allFiles(base_path('.ebextensions')), ...File::allFiles(base_path('.platform'))])
            ->merge(File::glob(base_path('.github/workflows/*')))->merge([base_path('.env.example'), base_path('phpunit.xml')]);
        foreach ($deployFiles as $file) {
            $this->assertStringNotContainsString('DEMO_ACTIVITY_ENABLED', File::get((string) $file), (string) $file);
        }
    }

    public function test_the_daily_spending_cap_skips_what_would_go_over(): void
    {
        config(['demo_activity.caps.spend_cents_per_day' => 2500]);

        $this->runDay('2026-10-26');

        $spent = DemoActivityAction::where('kind', 'redemption')->where('status', 'done')->sum('amount_cents');
        $this->assertLessThanOrEqual(2500, $spent);
        $this->assertTrue(DemoActivityAction::where('note', 'The daily spending cap is reached.')->exists());
    }

    public function test_the_daily_action_cap_stops_the_day(): void
    {
        config(['demo_activity.caps.actions_per_day' => 4]);

        $this->runDay('2026-10-26');

        $this->assertSame(4, DemoActivityAction::whereIn('status', ['done', 'failed'])->count());
        $this->assertStringContainsString('daily cap of 4 actions', DemoActivityDay::firstOrFail()->halted_reason);
    }

    public function test_the_daily_purchase_cap_limits_what_it_buys(): void
    {
        config(['demo_activity.caps.purchase_cents_per_day' => 50000]);

        $this->runDay('2026-11-02');

        $this->assertLessThanOrEqual(50000, DemoActivityAction::where('kind', 'purchase')->sum('amount_cents'));
        $this->assertNoNegativeBalances();
    }

    public function test_three_failures_stop_the_day(): void
    {
        $this->app->instance(RedemptionService::class, new class extends RedemptionService
        {
            public function __construct() {}

            public function redeem(...$args): Redemption
            {
                throw new RuntimeException('Something went wrong (fake).');
            }
        });

        $this->runDay('2026-10-26');

        $this->assertSame(3, DemoActivityAction::where('status', 'failed')->count());
        $this->assertStringContainsString('3 actions failed', DemoActivityDay::firstOrFail()->halted_reason);
        $this->assertStringContainsString('Something went wrong (fake).', DemoActivityAction::where('status', 'failed')->value('error'));
    }

    public function test_two_reconciliation_mismatches_in_a_row_stop_the_day(): void
    {
        // Stripe's webhook never arrives: Stripe has the payment, the ledger doesn't.
        $generator = app(ActivityGenerator::class);
        foreach (['12:00', '12:05', '12:10', '12:15'] as $time) {
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-26 '.$time, 'America/New_York'));
            $generator->tick();
        }

        $day = DemoActivityDay::firstOrFail();
        $this->assertStringContainsString('reconciliation did not match twice', $day->halted_reason);
        $this->assertFalse($day->last_reconciliation['matches']);
        $this->assertSame(['Stopped for today: '.$day->halted_reason], $generator->tick());
    }

    public function test_a_tick_that_breaks_never_throws(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-26 12:30', 'America/New_York'));
        DB::statement('DROP TABLE demo_activity_actions');

        $this->assertSame(0, Artisan::call('demo-activity:tick'));
        $this->assertStringContainsString('Tick failed', Artisan::output());
    }

    public function test_more_than_three_open_refund_requests_stop_new_ones(): void
    {
        $this->withoutSeededCorrections();
        foreach (range(1, 4) as $i) {
            $this->refundRequest($this->cleared(1234 + $i, Employee::orderBy('id')->skip($i + 10)->firstOrFail()));
        }

        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
            $this->runDay($date);
        }

        $action = DemoActivityAction::where('kind', DemoActivityAction::KIND_REFUND_REQUEST)->firstOrFail();
        $this->assertSame(DemoActivityAction::STATUS_SKIPPED, $action->status);
        $this->assertStringContainsString('4 refund requests are waiting for a person', $action->note);
        $this->assertSame(4, Correction::count());
    }

    public function test_it_never_touches_reviewers_applications_adjudication_eval_data_or_locks_and_sends_no_email(): void
    {
        Mail::fake();
        Notification::fake();
        $tables = fn () => collect(self::HANDS_OFF)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->all()])->all();
        $files = fn () => collect(File::allFiles(base_path('data')))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getPathname())])->all();
        [$tablesBefore, $filesBefore] = [$tables(), $files()];

        $this->runDay('2026-11-02');
        $this->runDay('2026-11-03');

        $this->assertGreaterThan(10, DemoActivityAction::where('status', 'done')->count());
        $this->assertSame($tablesBefore, $tables());
        $this->assertSame($filesBefore, $files());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_the_generator_only_uses_the_normal_services_and_never_decides(): void
    {
        $source = collect(File::allFiles(app_path('Services/DemoActivity')))->map(fn ($f) => File::get($f->getPathname()))->implode("\n")
            .File::get(app_path('Console/Commands/DemoActivityTick.php'));

        foreach ([
            'RedemptionReviewService', 'CorrectionDecisions', 'RedemptionDecision', 'Adjudication', 'ReviewerLinks', 'MerchantLabels',
            'MerchantReviewDecision', 'MerchantFeeSettings', 'PartnershipSettings', 'CreditLedgerEntry', 'MerchantPayout::create',
            'DB::table', 'DB::insert', 'DB::update', '->approve(', '->deny(', 'createTransfer', 'Mail::', 'Notification::',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    private function counts(): array
    {
        return collect(['demo_activity_days', 'demo_activity_actions', 'redemptions', 'credit_purchases', 'credit_ledger_entries', 'credit_allocations', 'corrections', 'pay_codes'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }
}
