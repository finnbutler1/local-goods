<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the single-BID demo. Everything here is synthetic. Merchant names are
 * invented and only styled after businesses in the Golden Triangle.
 *
 * Dates are relative to today so the history always looks recent. The seeded
 * spending totals exactly $180.00, so the balance is always a whole multiple
 * of $10 ($120 or $270 depending on how many allowance months fall in the
 * six-week window). The "suspicious redemption" demo relies on that.
 */
class DatabaseSeeder extends Seeder
{
    public const TIMEZONE = 'America/New_York';

    public function run(): void
    {
        $bid = Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);

        $employer = Employer::create([
            'bid_id' => $bid->id,
            'name' => 'Meridian Policy Group',
            'monthly_allowance_cents' => 15000,
        ]);

        $employee = Employee::create([
            'employer_id' => $employer->id,
            'display_name' => 'Jordan Rivera (demo)',
        ]);

        $merchants = collect([
            'cafe' => ['Farragut Grind', 'cafe', '1600 block of K St NW'],
            'kitchen' => ['Seventeenth Street Kitchen', 'restaurant', '1100 block of 17th St NW'],
            'noodle' => ['Connecticut Noodle Bar', 'restaurant', '1700 block of Connecticut Ave NW'],
            'deli' => ['Press Room Deli', 'restaurant', '1900 block of I St NW'],
            'salon' => ['K Street Cuts', 'salon', '1800 block of K St NW'],
            'cycle' => ['Lafayette Cycle Studio', 'fitness', '1500 block of H St NW'],
            'florist' => ['Triangle Stems', 'retail', '1200 block of 19th St NW'],
            'books' => ['Page & Quill Books', 'retail', '2000 block of L St NW'],
        ])->map(fn ($m) => Merchant::create([
            'bid_id' => $bid->id, 'name' => $m[0], 'category' => $m[1], 'address' => $m[2],
        ]));

        $now = Carbon::now(self::TIMEZONE);
        $windowStart = $now->copy()->subDays(42)->startOfMonth();

        // Monthly allowance on the 1st of each month in the six-week window.
        for ($month = $windowStart->copy(); $month->lte($now); $month->addMonth()) {
            CreditLedgerEntry::create([
                'employee_id' => $employee->id,
                'amount_cents' => $employer->monthly_allowance_cents,
                'type' => CreditLedgerEntry::TYPE_ALLOWANCE,
                'description' => $employer->name.' monthly allowance ('.$month->format('F').')',
                'created_at' => $month->copy()->setTime(6, 0)->utc(),
            ]);
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
        }

        DemoAccountSeeder::linkDemoEmployee();
    }
}
