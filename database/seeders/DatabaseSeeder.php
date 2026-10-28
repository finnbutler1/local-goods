<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\CreditLedgerEntry;
use App\Models\District;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantApplication;
use App\Models\MerchantApplicationEvent;
use App\Models\MerchantPayout;
use App\Models\MerchantProduct;
use App\Models\Partnership;
use App\Models\Redemption;
use App\Services\CreditLedger;
use App\Services\Districts\MerchantApplications;
use App\Services\Districts\PartnershipSettings;
use App\Support\SalesTax;
use App\Support\TaxBreakdown;
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
        'noodle' => ['Connecticut Noodle Bar', 'restaurant', '1100 block of Connecticut Ave NW'],
        'deli' => ['Press Room Deli', 'restaurant', '1900 block of I St NW'],
        'salon' => ['K Street Cuts', 'salon', '1800 block of K St NW'],
        'cycle' => ['Lafayette Cycle Studio', 'fitness', '1700 block of H St NW'],
        'florist' => ['Triangle Stems', 'retail', '1200 block of 19th St NW'],
        'books' => ['Page & Quill Books', 'retail', '2000 block of L St NW'],
    ];

    /**
     * Where each demo merchant is (ADR 0031): latitude and longitude of a DC
     * Master Address Repository address point on its block. The businesses
     * are fictional, so this is a point on the block, not a real shop. Every
     * one is inside the Golden Triangle boundary (DistrictBoundaryTest); the
     * districts migration copies the same values onto existing rows.
     */
    public const DEMO_COORDINATES = [
        'cafe' => [38.902184, -77.037528],
        'kitchen' => [38.904107, -77.038752],
        'noodle' => [38.904105, -77.039919],
        'deli' => [38.901178, -77.043744],
        'salon' => [38.902224, -77.042408],
        'cycle' => [38.900494, -77.040595],
        'florist' => [38.906107, -77.043869],
        'books' => [38.903408, -77.045785],
    ];

    /**
     * Each demo merchant's menu (ADR 0023): name, price in cents, short
     * description and, only where it differs from the merchant's default
     * (config/tax.php), a DC sales tax category (ADR 0027). Downtown DC prices. Display only: products are never sent
     * to Redemption Fraud Detection. No gift cards, which would be cash.
     */
    public const DEMO_MENUS = [
        'cafe' => [
            ['Drip coffee', 375, '12 oz, house blend'],
            ['Latte', 575, null],
            ['Cappuccino', 550, null],
            ['Cold brew', 525, null],
            ['Chai latte', 595, null],
            ['Croissant', 425, 'Butter, baked in the morning'],
            ['Blueberry muffin', 400, null],
            ['Avocado toast', 1150, 'Sourdough, chili flakes'],
        ],
        'kitchen' => [
            ['Grain bowl', 1650, 'Roast chicken, farro, greens'],
            ['Kitchen burger', 1800, 'With fries'],
            ['Seasonal salad', 1400, null],
            ['Soup of the day', 850, null],
            ['Side of fries', 600, null],
            ['Lemonade', 450, null],
        ],
        'noodle' => [
            ['Tonkotsu ramen', 1700, 'Pork broth, chashu, egg'],
            ['Spicy miso ramen', 1750, null],
            ['Vegetable ramen', 1550, 'Vegan'],
            ['Pork buns (2)', 900, null],
            ['Gyoza (6)', 850, null],
            ['Iced green tea', 375, null],
        ],
        'deli' => [
            ['Turkey club', 1350, null],
            ['Italian hero', 1425, 'Salami, ham, provolone'],
            ['Veggie wrap', 1200, null],
            ['Soup and half sandwich', 1275, null],
            ['Chips', 225, null],
            ['Fountain drink', 275, null],
        ],
        'salon' => [
            ['Classic cut', 4200, null],
            ['Cut and style', 7500, null],
            ['Beard trim', 2200, null],
            ['Blowout', 5500, null],
            ['Kids\' cut', 3000, 'Under 12'],
        ],
        'cycle' => [
            ['Drop-in class', 3200, '45-minute ride'],
            ['5-class pack', 14500, 'Use within 2 months'],
            ['Shoe rental', 400, null],
            // A health club's classes are taxable at the general rate; a smoothie is prepared food;
            // bottled water is a grocery item (SNAP eligible), not taxed.
            ['Bottled water', 300, null, 'exempt'],
            ['Recovery smoothie', 900, null, 'prepared_food'],
        ],
        'florist' => [
            ['Single stem', 600, null],
            ['Seasonal bouquet', 3800, null],
            ['Desk arrangement', 5500, 'Small, in a glass vase'],
            ['Potted orchid', 4800, null],
            ['Greeting card', 650, null],
        ],
        'books' => [
            ['Paperback', 1800, 'Most new paperbacks'],
            ['Hardcover', 3000, 'Most new hardcovers'],
            ['Notebook', 1400, null],
            ['Tote bag', 2200, null],
            ['Bookmark', 400, null],
        ],
    ];

    public function run(): void
    {
        $bid = Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);
        // The district row comes from the districts migration, never from the seed.
        $district = District::default();
        self::seedDistrictExtras($bid, $district);

        $employer = Employer::create([
            'bid_id' => $bid->id,
            'district_id' => $district->id,
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

        // Placed, approved and live in the district (ADR 0031), as if each had
        // gone through the joining pipeline before the demo starts.
        $merchants = collect(self::DEMO_MERCHANTS)->map(fn ($m, $key) => Merchant::create([
            'bid_id' => $bid->id, 'name' => $m[0], 'category' => $m[1], 'address' => $m[2],
            'district_id' => $district->id, 'latitude' => self::DEMO_COORDINATES[$key][0], 'longitude' => self::DEMO_COORDINATES[$key][1],
            'district_approved_at' => now(), 'live_at' => now(),
        ]));

        self::seedMenus($merchants);

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
                // A typed-in shop total with any tax already in it (ADR 0027).
                'tax_basis' => TaxBreakdown::INCLUDED,
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

    /** What ADR 0031 added to the demo, for demo:seed-if-empty on a demo seeded before it. */
    public static function seedDistrictExtras(Bid $bid, District $district): void
    {
        self::seedPartnership($bid, $district);
        self::seedSampleApplications();
    }

    /**
     * Two fictional sample applications (ADR 0031), labelled as such: one
     * approved (waiting for the shop to set up Stripe payouts) and one
     * waiting for the BID. Their eligibility suggestions are written here,
     * not asked of the AI, and say so. No merchant row: neither shop is a
     * participant. Their status links are thrown away.
     */
    public static function seedSampleApplications(): void
    {
        $samples = [
            [MerchantApplication::BID_APPROVED, [
                'business_name' => 'Nineteenth Street Tea House', 'address' => '1100 block of 18th St NW', 'category' => 'cafe',
                'contact_email' => 'tea-house@example.com',
                'independence_answer' => 'Fictional sample: one tea room, owned by a family in Takoma, no franchise or parent company.',
            ], [38.904439, -77.041331], 6],
            [MerchantApplication::ELIGIBILITY_CHECKED, [
                'business_name' => 'Desk & Dumpling', 'address' => '1900 block of L St NW', 'category' => 'restaurant',
                'contact_email' => 'desk-and-dumpling@example.com',
                'independence_answer' => 'Fictional sample: a single lunch counter run by its two cooks, who own it outright.',
            ], [38.903587, -77.044017], 2],
        ];

        foreach ($samples as [$status, $data, [$latitude, $longitude], $daysAgo]) {
            $at = now()->subDays($daysAgo);
            [$application] = app(MerchantApplications::class)->submit($data, isSample: true);
            $application->forceFill([
                'status' => $status, 'matched_address' => mb_strtoupper($data['address']), 'latitude' => $latitude, 'longitude' => $longitude,
                'inside_district' => true, 'ai_eligible' => true, 'ai_classification' => 'independent', 'ai_confidence' => 0.75,
                'ai_reasoning' => 'Seeded example, not an AI answer: a fictional single-location shop, so the rule would treat it as independent if that is true.',
                'ai_needs_review' => false, 'ai_prompt_version' => null, 'ai_model' => self::SEEDED_SUGGESTION,
                'created_at' => $at, 'updated_at' => $at,
            ])->save();
            MerchantApplicationEvent::where('merchant_application_id', $application->id)->update(['created_at' => $at]);
            MerchantApplicationEvent::create(['merchant_application_id' => $application->id, 'from_status' => MerchantApplication::APPLIED,
                'to_status' => MerchantApplication::ELIGIBILITY_CHECKED, 'actor' => MerchantApplicationEvent::ACTOR_SEED, 'created_at' => $at->copy()->addHours(3),
                'reason' => 'Demo seed: address inside the district. The suggestion was written by the seed, not asked of the AI.']);
            if ($status === MerchantApplication::BID_APPROVED) {
                MerchantApplicationEvent::create(['merchant_application_id' => $application->id, 'from_status' => MerchantApplication::ELIGIBILITY_CHECKED,
                    'to_status' => MerchantApplication::BID_APPROVED, 'actor' => MerchantApplicationEvent::ACTOR_SEED, 'created_at' => $at->copy()->addDay(),
                    'reason' => 'Demo seed: approved as an example. A real approval is a BID admin\'s decision, with their reason.']);
            }
        }
    }

    /** ai_model on a seeded sample: the suggestion was written by the seed, never asked of the AI. */
    public const SEEDED_SUGGESTION = 'seeded example';

    /**
     * The demo partnership (ADR 0031): the Golden Triangle BID, exclusive and
     * active, no revenue share. Labelled everywhere as a demo, not an actual
     * agreement. Also used by demo:seed-if-empty for a demo seeded before
     * partnerships existed.
     */
    public static function seedPartnership(Bid $bid, District $district): Partnership
    {
        return app(PartnershipSettings::class)->create(null, $bid, $district, [
            'status' => Partnership::STATUS_ACTIVE, 'exclusive' => true, 'starts_on' => '2026-10-01', 'ends_on' => null,
            'revenue_share_bps' => 0, 'data_terms' => Partnership::DEFAULT_DATA_TERMS,
        ], 'Demo partnership seeded with the demo data. Not an actual agreement with the Golden Triangle BID.', isDemo: true);
    }

    /**
     * Gives each demo merchant its menu from DEMO_MENUS. Also used by
     * demo:seed-if-empty to add the menus to an already seeded database.
     *
     * @param  Collection<string, Merchant>  $merchants  keyed like DEMO_MERCHANTS
     */
    public static function seedMenus($merchants): void
    {
        foreach (self::DEMO_MENUS as $key => $products) {
            foreach ($products as $position => $product) {
                [$name, $cents, $description] = $product;
                MerchantProduct::create([
                    'merchant_id' => $merchants[$key]->id, 'name' => $name, 'price_cents' => $cents,
                    'description' => $description, 'position' => $position,
                    'tax_category' => $product[3] ?? SalesTax::defaultFor($merchants[$key]),
                ]);
            }
        }
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
