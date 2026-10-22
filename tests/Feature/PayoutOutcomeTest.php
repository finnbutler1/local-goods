<?php

namespace Tests\Feature;

use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Support\PayoutOutcome;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sentence after an approval says what happened to the merchant's money
 * for every payout row state, and only says the money moved when a Stripe
 * test transfer was sent. Display only: it never writes.
 */
class PayoutOutcomeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{?string, bool, string}> */
    public static function states(): array
    {
        return [
            'no Stripe row (simulated)' => [null, false, 'Now owed to the merchant (simulated, no money moved).'],
            'simulated row' => [MerchantPayout::STATUS_SIMULATED, false, 'Now owed to the merchant (simulated, no money moved).'],
            'sent' => [MerchantPayout::STATUS_SENT, true, 'Stripe test transfer sent.'],
            'pending' => [MerchantPayout::STATUS_PENDING, true, 'Stripe test transfer started; waiting for Stripe to confirm it.'],
            'owed, not onboarded' => [MerchantPayout::STATUS_OWED, false, "Owed to the merchant through Stripe. No transfer yet: they haven't finished Stripe onboarding."],
            'owed, onboarded' => [MerchantPayout::STATUS_OWED, true, "Owed to the merchant through Stripe. The test transfer couldn't be made yet, so nothing moved."],
            'failed' => [MerchantPayout::STATUS_FAILED, true, "Owed to the merchant through Stripe. The test transfer couldn't be made yet, so nothing moved."],
            'reversed' => [MerchantPayout::STATUS_REVERSED, true, 'The Stripe test transfer was reversed, so nothing is with the merchant.'],
        ];
    }

    #[DataProvider('states')]
    public function test_each_payout_state_has_an_honest_sentence(?string $status, bool $onboarded, string $sentence): void
    {
        $this->seed(DatabaseSeeder::class);
        $redemption = Redemption::where('status', Redemption::STATUS_APPROVED)->doesntHave('stripePayout')->firstOrFail();
        $redemption->merchant->forceFill(['stripe_account_id' => $onboarded ? 'acct_test_X' : null, 'stripe_transfers_active' => $onboarded])->save();
        if ($status !== null) {
            MerchantPayout::create(['merchant_id' => $redemption->merchant_id, 'amount_cents' => $redemption->amount_cents, 'status' => $status, 'redemption_id' => $redemption->id]);
        }
        $payouts = MerchantPayout::count();

        $this->assertSame($sentence, PayoutOutcome::sentence($redemption));
        $this->assertSame($status === MerchantPayout::STATUS_SENT, str_contains($sentence, 'sent'));
        $this->assertSame($payouts, MerchantPayout::count());
    }
}
