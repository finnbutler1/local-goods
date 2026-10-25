<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\DemoResetter;
use App\Support\MerchantFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded demo (and so live after a Reset demo) charges no merchant fee
 * (ADR 0029): no setting row, every redemption, payout and correction at
 * 0, and no fee ledger lines.
 */
class MerchantFeeSeedTest extends TestCase
{
    use RefreshDatabase;

    private const FEE_TYPES = [
        CreditLedgerEntry::TYPE_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE,
        CreditLedgerEntry::TYPE_MERCHANT_FEE_RETURNED, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED,
    ];

    public function test_the_seed_charges_no_merchant_fee(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertNoFee();

        app(DemoResetter::class)->reset();
        $this->assertNoFee();
    }

    public function test_the_fee_history_is_not_a_demo_table(): void
    {
        $this->assertNotContains('merchant_fee_changes', DemoResetter::DEMO_TABLES);
    }

    private function assertNoFee(): void
    {
        $this->assertTrue(MerchantFee::current()->isNone());
        $this->assertGreaterThan(0, Redemption::count());
        $this->assertSame(0, Redemption::where('merchant_fee_cents', '>', 0)->orWhere('merchant_fee_bps', '>', 0)->orWhere('merchant_fee_fixed_cents', '>', 0)->count());
        $this->assertSame(0, MerchantPayout::where('fee_cents', '>', 0)->count());
        $this->assertSame(0, Correction::where('fee_cents', '>', 0)->count());
        $this->assertSame(0, CreditLedgerEntry::whereIn('type', self::FEE_TYPES)->count());
        $this->assertSame(0, CorrectionAction::where('kind', 'fee_only')->count());
    }
}
