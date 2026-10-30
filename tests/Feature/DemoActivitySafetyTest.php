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

        $deployFiles = collect([...File::allFiles(base_path('.ebextensions')), ...File::allFiles(base_path('.platform')), ...File::allFiles(base_path('scripts'))])
            ->merge(File::glob(base_path('.github/workflows/*')))->merge([base_path('.env.example'), base_path('phpunit.xml')]);
        foreach ($deployFiles as $file) {
            // Mentioning it is fine (the wrapper reads it); setting it to anything is not.
            $this->assertDoesNotMatchRegularExpression('/DEMO_ACTIVITY_ENABLED\s*[:=]\s*["\']?\w/', File::get((string) $file), (string) $file);
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

    public function test_a_webhook_arriving_late_does_not_stop_the_day(): void
    {
        // The top-up is paid in Stripe at 07:00, but its webhook only arrives after 07:10.
        $this->tickWithoutWebhooks('07:00');
        $this->assertSame(1, DemoActivityAction::where('kind', 'purchase')->where('status', 'done')->count());

        foreach (['07:05', '07:10'] as $time) {
            $this->assertContains("Reconciliation is waiting for Stripe's webhook (not counted; checking again next tick).", $this->tickWithoutWebhooks($time), $time);
        }
        $day = DemoActivityDay::firstOrFail();
        $this->assertNull($day->halted_reason);
        $this->assertSame(0, $day->reconciliation_misses);
        $this->assertTrue($day->last_reconciliation['waiting_for_webhooks']);

        $this->deliverPaymentWebhooks();
        $this->assertContains('Reconciliation matches.', $this->tickWithoutWebhooks('07:15'));

        $day->refresh();
        $this->assertNull($day->halted_reason);
        $this->assertTrue($day->last_reconciliation['matches']);
    }

    public function test_a_webhook_that_never_arrives_stops_the_day_once_the_grace_is_over(): void
    {
        // Stripe has the payment from 07:00; the ledger never hears of it.
        foreach (['07:00', '07:05', '07:10', '07:15', '07:20', '07:25'] as $time) {
            $this->tickWithoutWebhooks($time);
        }
        $this->assertNull(DemoActivityDay::firstOrFail()->halted_reason, 'Within the 30 minutes: still waiting');

        // From 07:30 the wait counts as a mismatch, and two in a row stop the day.
        $this->assertContains('Reconciliation did not match (once; checking again next tick).', $this->tickWithoutWebhooks('07:30'));
        $this->tickWithoutWebhooks('07:35');

        $day = DemoActivityDay::firstOrFail();
        $this->assertStringContainsString('reconciliation did not match twice', $day->halted_reason);
        $this->assertFalse($day->last_reconciliation['matches']);
        $this->assertFalse($day->last_reconciliation['waiting_for_webhooks']);
        $this->assertSame(['Stopped for today: '.$day->halted_reason], $this->tickWithoutWebhooks('07:40'));
    }

    public function test_a_real_mismatch_still_stops_the_day_on_the_second_check(): void
    {
        // Run to the first Stripe transfer, everything matching.
        $t = \Carbon\CarbonImmutable::parse('2026-10-26 07:00', 'America/New_York');
        while (! DB::table('merchant_payouts')->where('status', 'sent')->exists()) {
            $this->tickAt($t->format('Y-m-d H:i'));
            $t = $t->addMinutes(5);
            $this->assertLessThan('19:00', $t->format('H:i'), 'No Stripe transfer was sent');
        }
        $this->assertTrue(DemoActivityDay::firstOrFail()->last_reconciliation['matches']);

        // The ledger now disagrees with Stripe about that transfer: no webhook explains it.
        DB::table('merchant_payouts')->where('status', 'sent')->orderBy('id')->limit(1)->update(['amount_cents' => DB::raw('amount_cents + 100')]);

        $checks = [];
        for (; $t->format('H:i') < '19:00' && DemoActivityDay::firstOrFail()->halted_reason === null; $t = $t->addMinutes(5)) {
            $lines = $this->tickAt($t->format('Y-m-d H:i'));
            $checks = [...$checks, ...array_values(array_filter($lines, fn ($l) => str_starts_with($l, 'Reconciliation')))];
        }

        $day = DemoActivityDay::firstOrFail();
        $this->assertStringContainsString('reconciliation did not match twice', $day->halted_reason);
        $this->assertSame(['Reconciliation did not match (once; checking again next tick).'], $checks, 'Halted on the second check, never waiting');
        $this->assertFalse($day->last_reconciliation['waiting_for_webhooks']);
    }

    public function test_reconciliation_is_checked_again_after_a_tick_sends_transfers(): void
    {
        $t = \Carbon\CarbonImmutable::parse('2026-10-26 07:00', 'America/New_York');
        do {
            $sent = DB::table('merchant_payouts')->where('status', 'sent')->count();
            $lines = $this->tickAt($t->format('Y-m-d H:i'));
            $at = $t;
            $t = $t->addMinutes(5);
        } while (DB::table('merchant_payouts')->where('status', 'sent')->count() === $sent && $t->format('H:i') < '19:00');

        $now = DB::table('merchant_payouts')->where('status', 'sent')->count();
        $this->assertGreaterThan($sent, $now, 'A tick sent a transfer');
        $this->assertSame('Reconciliation matches.', end($lines), 'Checked after the transfers went out');

        $day = DemoActivityDay::firstOrFail();
        $this->assertTrue($at->equalTo(\Carbon\CarbonImmutable::parse($day->last_reconciliation['at'])));
        $this->assertStringEndsWith('/'.$now, $day->last_reconciliation['state'], 'The recorded result includes the new transfers');
    }

    /** One tick at this time on Monday 2026-10-26, with Stripe's webhooks held back. */
    private function tickWithoutWebhooks(string $time): array
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-26 '.$time, 'America/New_York'));

        return app(ActivityGenerator::class)->tick();
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
