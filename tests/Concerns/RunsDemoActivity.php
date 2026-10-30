<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\DemoActivity\ActivityGenerator;
use App\Services\Payments\StripeOnboarding;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeClaudeClient;

/**
 * Shared set-up for the demo activity generator tests (ADR 0032): the demo
 * seeded on a fixed date, fake Stripe in test mode with every demo merchant
 * onboarded, the merchant login linked, the generator switched on, and a
 * fake AI. Use with RefreshDatabase, UsesFakeStripe and SeedsOnAFixedDate.
 */
trait RunsDemoActivity
{
    protected FakeClaudeClient $claude;

    /** @var list<string> PaymentIntents already delivered by webhook */
    private array $delivered = [];

    protected function startDemoActivity(?FakeClaudeClient $claude = null): void
    {
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        config(['demo_activity.enabled' => true]);
        $this->claude = $claude ?? FakeClaudeClient::verdict(10, 'clear');
        $this->app->instance(ClaudeClient::class, $this->claude);

        foreach (Merchant::orderBy('id')->get() as $i => $merchant) {
            $id = 'acct_test_demo'.$i;
            $this->stripe->accounts[$id] = ['id' => $id];
            app(StripeOnboarding::class)->apply($merchant, $this->stripe->completeOnboarding($id));
        }

        $login = User::factory()->role(Role::Merchant)->create(['name' => 'Priya Natarajan']);
        Merchant::where('name', config('demo.accounts.merchant.business'))->update(['user_id' => $login->id]);
    }

    /** One tick at this DC time, then the webhooks Stripe would send for the payments it made. */
    protected function tickAt(string $dcTime): array
    {
        Carbon::setTestNow(Carbon::parse($dcTime, 'America/New_York'));
        $lines = app(ActivityGenerator::class)->tick();
        $this->deliverPaymentWebhooks();

        return $lines;
    }

    /** Every five minutes from 07:00 to 18:55 on this date. */
    protected function runDay(string $date): void
    {
        for ($t = CarbonImmutable::parse($date.' 07:00', 'America/New_York'); $t->format('H:i') < '19:00'; $t = $t->addMinutes(5)) {
            $this->tickAt($t->format('Y-m-d H:i'));
        }
    }

    protected function deliverPaymentWebhooks(): void
    {
        foreach ($this->stripe->paymentIntents as $pi) {
            if (! in_array($pi['id'], $this->delivered, true)) {
                $this->delivered[] = $pi['id'];
                $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi))->assertOk();
            }
        }
    }

    protected function assertNoNegativeBalances(): void
    {
        foreach (Employee::all() as $employee) {
            $this->assertGreaterThanOrEqual(0, $employee->balanceCents(), $employee->initials());
        }
    }
}
