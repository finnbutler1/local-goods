<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\StripeEvent;
use App\Services\Payments\StripeReconciliation;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The step 7 Stripe trial (scripts/stripe-trial-corrections.php, ADR 0028)
 * ran against a shared test sandbox. Its objects are tagged
 * lg_env=scratch, lg_instance=step7-trial, so neither local nor live Local
 * Goods may act on them: the webhook ignores every one of its real events
 * before writing anything, and reconciliation never counts them. Also
 * checks the committed fixtures stayed scrubbed.
 */
class StripeTrialFixturesTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private const DIR = __DIR__.'/../Fixtures/stripe/corrections';

    /** @return list<array> the trial's real events, oldest first */
    public static function trialEvents(): array
    {
        return array_map(fn ($file) => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), glob(self::DIR.'/*.json'));
    }

    public function test_every_trial_object_is_tagged_as_another_environment(): void
    {
        $events = self::trialEvents();
        $this->assertCount(11, $events);

        foreach ($events as $event) {
            $this->assertSame(StripeEnvironment::OTHER, StripeEnvironment::of($event['data']['object']['metadata']), $event['type']);
        }
    }

    public function test_the_webhook_ignores_every_trial_event_and_writes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $ledger = CreditLedgerEntry::count();

        foreach (self::trialEvents() as $event) {
            $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'ignored: other environment']);
        }

        $this->assertSame(0, StripeEvent::count());
        $this->assertSame($ledger, CreditLedgerEntry::count());
    }

    public function test_reconciliation_never_counts_a_trial_transfer(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $transfer = collect(self::trialEvents())->firstWhere('type', 'transfer.created')['data']['object'];
        $this->stripe->transfers[] = $transfer;

        $report = app(StripeReconciliation::class)->report();

        $this->assertSame([], $report['mismatches']);
        $this->assertSame(0, $report['stripe']['transfers']);
        $this->assertSame(0, $report['other']['transfers'] + $report['other']['payments']);
    }

    public function test_the_fixtures_hold_no_real_ids_emails_or_card_details(): void
    {
        foreach (glob(self::DIR.'/*.json') as $file) {
            $text = file_get_contents($file);

            // Every Stripe id is a fake_ placeholder (real ids mix in digits or capitals).
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_])(?!fake_)[a-z]{2,6}_(?=[a-z]*[0-9A-Z])[A-Za-z0-9]{8,}/', $text, basename($file));
            $this->assertStringNotContainsString('@', $text, basename($file));
            foreach (['authorization_code', 'network_transaction_id', 'exp_month', 'exp_year', 'last4', 'fingerprint'] as $field) {
                $this->assertDoesNotMatchRegularExpression('/"'.$field.'": "(?!fake-removed)[^"]+"/', $text, basename($file).' '.$field);
            }
        }
    }
}
