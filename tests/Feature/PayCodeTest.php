<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\PayCodes;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Pay codes (ADR 0026): one-time, one employee, one merchant, one amount,
 * 10 minutes. Nothing moves until the merchant confirms, and confirming is
 * an ordinary RedemptionService::redeem.
 */
class PayCodeTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $this->claude);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function codes(): PayCodes
    {
        return app(PayCodes::class);
    }

    private function jordan(): Employee
    {
        return Employee::orderBy('id')->firstOrFail();
    }

    private function grind(): Merchant
    {
        return Merchant::where('name', 'Farragut Grind')->firstOrFail();
    }

    private function deli(): Merchant
    {
        return Merchant::where('name', 'Press Room Deli')->firstOrFail();
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        $this->fail('Expected the pay code to be refused.');
    }

    public function test_codes_are_six_characters_from_an_alphabet_with_nothing_that_reads_two_ways(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, PayCodes::ALPHABET);
        }

        for ($i = 0; $i < 200; $i++) {
            $code = PayCodes::generate();
            $this->assertSame(6, strlen($code));
            $this->assertSame(6, strspn($code, PayCodes::ALPHABET));
        }

        $this->assertSame('K7M4PQ', PayCodes::normalize(' k7m-4pq '));
        $this->assertNull(PayCodes::normalize('K7M4P0'));
        $this->assertNull(PayCodes::normalize('K7M4P'));
    }

    public function test_getting_a_code_moves_nothing_and_stores_no_plain_code(): void
    {
        $entries = CreditLedgerEntry::count();
        $redemptions = Redemption::count();
        $balance = $this->jordan()->balanceCents();

        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);

        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame($redemptions, Redemption::count());
        $this->assertSame($balance, $this->jordan()->balanceCents());
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->status);
        $this->assertTrue($payCode->expires_at->equalTo(now()->addMinutes(10)));

        $row = (array) DB::table('pay_codes')->find($payCode->id);
        $this->assertSame(PayCodes::hash($payCode->code), $row['code_hash']);
        foreach ($row as $value) {
            $this->assertStringNotContainsString($payCode->code, (string) $value);
        }
        $this->assertArrayNotHasKey('code', $payCode->toArray());
    }

    public function test_one_live_code_per_employee(): void
    {
        $first = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $second = $this->codes()->issue($this->jordan(), $this->deli(), 1200);

        $this->assertSame(PayCode::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(PayCode::BY_REPLACED, $first->fresh()->cancelled_by);
        $this->assertSame($second->id, $this->codes()->liveFor($this->jordan())->id);
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->lookup($this->grind(), $first->code)));
    }

    public function test_a_code_for_more_than_the_balance_is_refused(): void
    {
        $this->assertSame('That is more than the available balance.',
            $this->refusal(fn () => $this->codes()->issue($this->jordan(), $this->grind(), $this->jordan()->balanceCents() + 1)));
        $this->assertSame(0, PayCode::where('status', PayCode::STATUS_ACTIVE)->count());
    }

    public function test_codes_expire_after_ten_minutes(): void
    {
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);

        Carbon::setTestNow(now()->addMinutes(9)->addSeconds(59));
        $this->assertSame($payCode->id, $this->codes()->lookup($this->grind(), $payCode->code)->id);

        Carbon::setTestNow(now()->addSecond());
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->lookup($this->grind(), $payCode->code)));
        $this->assertSame(PayCode::STATUS_EXPIRED, $payCode->fresh()->status);
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->grind())));
        $this->assertNull($this->codes()->liveFor($this->jordan()));
    }

    public function test_an_expired_code_found_before_it_expired_cannot_be_confirmed(): void
    {
        $payCode = $this->codes()->lookup($this->grind(), $this->codes()->issue($this->jordan(), $this->grind(), 850)->code);
        Carbon::setTestNow(now()->addMinutes(11));

        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->grind())));
        $this->assertSame(0, Redemption::where('channel', Redemption::CHANNEL_IN_PERSON)->where('scored_by', '!=', 'seed')->count());
    }

    public function test_a_cancelled_code_cannot_be_found_or_confirmed(): void
    {
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $this->codes()->cancelByEmployee($payCode);

        $this->assertSame(PayCode::BY_EMPLOYEE, $payCode->fresh()->cancelled_by);
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->lookup($this->grind(), $payCode->code)));
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->grind())));
    }

    public function test_a_declined_code_cannot_be_confirmed(): void
    {
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $this->codes()->decline($payCode, $this->grind());

        $this->assertSame(PayCode::BY_MERCHANT, $payCode->fresh()->cancelled_by);
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->grind())));
    }

    public function test_another_merchant_gets_the_same_message_as_for_a_made_up_code(): void
    {
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);

        $wrongMerchant = $this->refusal(fn () => $this->codes()->lookup($this->deli(), $payCode->code));
        $madeUp = $this->refusal(fn () => $this->codes()->lookup($this->deli(), 'ZZZZZZ'));

        $this->assertSame(PayCodes::NOT_VALID, $wrongMerchant);
        $this->assertSame($madeUp, $wrongMerchant);
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->deli())));
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->decline($payCode, $this->deli())));
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
    }

    public function test_five_misses_a_minute_lock_the_merchant_out_even_with_the_right_code(): void
    {
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);

        for ($i = 0; $i < PayCodes::MAX_FAILURES; $i++) {
            $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->lookup($this->grind(), 'ZZZZZ'.($i + 2))));
        }

        $this->assertSame(PayCodes::TOO_MANY_TRIES, $this->refusal(fn () => $this->codes()->lookup($this->grind(), $payCode->code)));
        // Another merchant has its own count.
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->lookup($this->deli(), 'ZZZZZZ')));

        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertSame($payCode->id, $this->codes()->lookup($this->grind(), $payCode->code)->id);
    }

    public function test_confirming_makes_one_in_person_redemption_through_redemption_service(): void
    {
        $balance = $this->jordan()->balanceCents();
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);

        $redemption = $this->codes()->confirm($this->codes()->lookup($this->grind(), $payCode->code), $this->grind());

        $this->assertSame(Redemption::CHANNEL_IN_PERSON, $redemption->fresh()->channel);
        $this->assertSame(Redemption::STATUS_APPROVED, $redemption->status);
        $this->assertSame(850, $redemption->amount_cents);
        $this->assertSame($balance - 850, $this->jordan()->balanceCents());
        $this->assertCount(1, $this->claude->calls);
        $this->assertSame(PayCode::STATUS_USED, $payCode->fresh()->status);
        $this->assertSame($redemption->id, $payCode->fresh()->redemption_id);

        // Single use: a second confirm, or a second lookup, gets nothing.
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->grind())));
        $this->assertSame(PayCodes::NOT_VALID, $this->refusal(fn () => $this->codes()->lookup($this->grind(), $payCode->code)));
        $this->assertSame(1, Redemption::where('channel', Redemption::CHANNEL_IN_PERSON)->where('scored_by', '!=', 'seed')->count());
        $this->assertCount(1, $this->claude->calls);
    }

    public function test_menu_items_on_the_code_go_on_the_redemption(): void
    {
        $latte = $this->grind()->products()->where('name', 'Latte')->firstOrFail();
        $items = [['merchant_product_id' => $latte->id, 'name' => 'Latte', 'unit_price_cents' => $latte->price_cents, 'quantity' => 2]];
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 2 * $latte->price_cents, $items);

        $this->assertSame('2× Latte', $payCode->itemsSummary());
        $redemption = $this->codes()->confirm($payCode, $this->grind());

        $this->assertSame('2× Latte', $redemption->load('items')->itemsSummary());
    }

    public function test_a_held_payment_is_held_exactly_like_to_go(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold', 'Unusual amount.'));
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $earned = $this->grind()->payableCents();

        $redemption = $this->codes()->confirm($payCode, $this->grind());

        $this->assertSame(Redemption::STATUS_UNDER_REVIEW, $redemption->status);
        $this->assertSame($earned, $this->grind()->payableCents());
        $this->assertSame(PayCode::STATUS_USED, $payCode->fresh()->status);
    }

    public function test_the_balance_is_checked_again_when_the_merchant_confirms(): void
    {
        $balance = $this->jordan()->balanceCents();
        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), $balance);

        // Spent elsewhere in the meantime.
        app(RedemptionService::class)->redeem($this->jordan(), $this->deli(), 100);
        $entries = CreditLedgerEntry::count();

        $this->assertSame(PayCodes::NOT_ENOUGH_CREDITS, $this->refusal(fn () => $this->codes()->confirm($payCode, $this->grind())));
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame(PayCode::STATUS_CANCELLED, $payCode->fresh()->status);
        $this->assertSame(PayCode::BY_BALANCE, $payCode->fresh()->cancelled_by);
        $this->assertSame(0, Redemption::where('channel', Redemption::CHANNEL_IN_PERSON)->where('scored_by', '!=', 'seed')->count());
    }

    public function test_a_code_is_never_written_to_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(new \RuntimeException('API down')));

        $payCode = $this->codes()->issue($this->jordan(), $this->grind(), 850);
        $this->refusal(fn () => $this->codes()->lookup($this->deli(), $payCode->code));
        $this->codes()->confirm($this->codes()->lookup($this->grind(), $payCode->code), $this->grind());

        $this->assertNotEmpty($logged, 'The failed AI call should have logged something.');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($payCode->code, $line);
        }
    }

    private static function NOT_VALID(): string
    {
        return PayCodes::NOT_VALID;
    }
}
