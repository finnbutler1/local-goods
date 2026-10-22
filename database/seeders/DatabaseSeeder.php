<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\CreditLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seeds the single-BID demo. Everything here is synthetic. Merchant names are
 * invented and only styled after businesses in the Golden Triangle; real
 * merchant candidates (data/merchant_candidates.csv) never appear here.
 *
 * Dates are relative to today so the history always looks recent. Meridian
 * buys credits each month and gives each enrolled employee their allowance on
 * the 1st; merchants are paid out (simulated, no Stripe) every Friday.
 *
 * Jordan Rivera, the demo employee login, is always the first employee and
 * keeps this exact history: spending totals $180.00, so the balance is always
 * a whole multiple of $10 ($120 or $270 depending on how many allowance
 * months fall in the six-week window). The "suspicious redemption" demo and
 * the fraud eval's signal check rely on that. The other 39 employees and the
 * planted collusion patterns are in DemoHistorySeeder.
 */
class DatabaseSeeder extends Seeder
{
    public const TIMEZONE = 'America/New_York';

    /** Jordan plus DemoHistorySeeder's 39, out of Meridian's 120 staff. */
    public const ENROLLED = 40;

    /**
     * The fictional demo merchants: name, category, address. Only these can
     * be given a Stripe test account by the platform (DemoMerchantStripeSetup).
     */
    public const DEMO_MERCHANTS = [
        'cafe' => ['Farragut Grind', 'cafe', '1600 block of K St NW'],
        'kitchen' => ['Seventeenth Street Kitchen', 'restaurant', '1100 block of 17th St NW'],
        'noodle' => ['Connecticut Noodle Bar', 'restaurant', '1700 block of Connecticut Ave NW'],
        'deli' => ['Press Room Deli', 'restaurant', '1900 block of I St NW'],
        'salon' => ['K Street Cuts', 'salon', '1800 block of K St NW'],
        'cycle' => ['Lafayette Cycle Studio', 'fitness', '1500 block of H St NW'],
        'florist' => ['Triangle Stems', 'retail', '1200 block of 19th St NW'],
        'books' => ['Page & Quill Books', 'retail', '2000 block of L St NW'],
    ];

    public function run(): void
    {
        $bid = Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);

        $employer = Employer::create([
            'bid_id' => $bid->id,
            'name' => 'Meridian Policy Group',
            'monthly_allowance_cents' => 15000,
            'staff_count' => 120,
            // Program setup on the employer dashboard: room above the $6,000
            // of monthly allowances for occasions. Changes no seeded history.
            'monthly_budget_cents' => 750000,
            'welcome_cents' => 5000,
            'birthday_cents' => 2500,
            'recognition_cents' => 5000,
        ]);

        $employee = Employee::create([
            'employer_id' => $employer->id,
            'display_name' => 'Jordan Rivera (demo)',
        ]);

        $merchants = collect(self::DEMO_MERCHANTS)->map(fn ($m) => Merchant::create([
            'bid_id' => $bid->id, 'name' => $m[0], 'category' => $m[1], 'address' => $m[2],
        ]));

        $ledger = app(CreditLedger::class);
        $now = Carbon::now(self::TIMEZONE);

        // One purchase a month covering every enrolled employee's allowance.
        $firstMonth = $now->copy()->subWeeks(DemoHistorySeeder::WEEKS)->startOfMonth();
        for ($month = $firstMonth->copy(); $month->lte($now); $month->addMonth()) {
            $ledger->purchase($employer, self::ENROLLED * $employer->monthly_allowance_cents,
                'Invoice MPG-'.$month->format('Y-m'), $month->copy()->setTime(5, 0)->utc());
        }

        // Jordan's monthly allowance on the 1st of each month in the six-week window.
        $windowStart = $now->copy()->subDays(42)->startOfMonth();
        for ($month = $windowStart->copy(); $month->lte($now); $month->addMonth()) {
            $ledger->allocate($employer, $employee, $employer->monthly_allowance_cents, $month->copy()->setTime(6, 0)->utc());
        }

        // [days ago, merchant, cents, local time]
        $history = [
            [41, 'cafe', 650, '08:40'],
            [39, 'kitchen', 1475, '12:20'],
            [36, 'books', 1300, '13:05'],
            [34, 'cafe', 575, '09:10'],
            [31, 'noodle', 1690, '12:45'],
            [28, 'florist', 3200, '16:20'],
            [22, 'cafe', 725, '08:55'],
            [20, 'cycle', 2800, '07:15'],
            [17, 'kitchen', 2140, '13:00'],
            [10, 'noodle', 1545, '12:30'],
            [6, 'cafe', 610, '09:00'],
            [3, 'deli', 1290, '12:15'],
        ];

        foreach ($history as [$daysAgo, $key, $cents, $time]) {
            [$hour, $minute] = explode(':', $time);
            $at = $now->copy()->subDays($daysAgo)->setTime((int) $hour, (int) $minute)->utc();
            $merchant = $merchants[$key];

            $redemption = Redemption::create([
                'employee_id' => $employee->id,
                'merchant_id' => $merchant->id,
                'amount_cents' => $cents,
                'status' => Redemption::STATUS_APPROVED,
                'scored_by' => 'seed',
                'created_at' => $at,
            ]);

            CreditLedgerEntry::create([
                'employee_id' => $employee->id,
                'amount_cents' => -$cents,
                'type' => CreditLedgerEntry::TYPE_REDEMPTION,
                'redemption_id' => $redemption->id,
                'description' => 'Redemption at '.$merchant->name,
                'created_at' => $at,
            ]);

            $ledger->creditMerchant($redemption, $at);
        }

        (new DemoHistorySeeder)->run($employer, $merchants, $now);

        $this->payMerchantsWeekly($ledger, $merchants, $now);

        DemoAccountSeeder::linkDemoEmployee();
        DemoAccountSeeder::linkDemoMerchant();
    }

    /**
     * Every Friday at 10 AM, each merchant is paid what it earned before that
     * day. Simulated: recorded in the ledger, no Stripe transfer.
     *
     * @param  Collection<string, Merchant>  $merchants
     */
    private function payMerchantsWeekly(CreditLedger $ledger, $merchants, Carbon $now): void
    {
        $friday = $now->copy()->subWeeks(DemoHistorySeeder::WEEKS)->next(Carbon::FRIDAY)->setTime(10, 0);

        for (; $friday->lt($now); $friday->addWeek()) {
            foreach ($merchants as $merchant) {
                $owed = (int) $merchant->ledgerEntries()->where('created_at', '<', $friday->copy()->startOfDay()->utc())->sum('amount_cents');

                if ($owed > 0) {
                    $ledger->payout($merchant, $owed, MerchantPayout::STATUS_SIMULATED, $friday->copy()->utc());
                }
            }
        }
    }
}
