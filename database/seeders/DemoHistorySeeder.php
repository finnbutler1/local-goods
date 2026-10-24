<?php

namespace Database\Seeders;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use App\Support\SalesTax;
use App\Support\TaxBreakdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Eight weeks of synthetic history for 39 more Meridian employees, on top of
 * Jordan's hand-written history in DatabaseSeeder. Every person and
 * transaction is invented, and every redemption is at one of the eight
 * fictional demo merchants. The same day always gives the same history (a
 * fixed random seed), so Reset demo restores exactly this state.
 *
 * Two collusion patterns are planted for a future pattern check to find. Each
 * redemption in them looks ordinary on its own, which is why they were
 * approved; the pattern only shows across redemptions:
 *
 * A. Credit-to-cash at one merchant. PATTERN_A_EMPLOYEE spends nothing all
 *    month, then the day after each allowance makes two round-dollar
 *    redemptions ($80 and $60) at PATTERN_A_MERCHANT within the hour, around
 *    7:30 PM, using $140 of the $150. They never redeem anywhere else.
 *
 * One completed in-person payment (ADR 0026) is added last: the first of
 * IN_PERSON_EMPLOYEES with enough credit left paid IN_PERSON_MERCHANT at the
 * counter with a pay code yesterday morning, IN_PERSON_CENTS as a bare
 * amount. It is not part of either pattern.
 *
 * Then three menu orders with DC sales tax added (ADR 0027) at
 * TAXED_ORDER_MERCHANT, by TAXED_ORDER_EMPLOYEES: one last month and two in
 * the last two days (early in a month those fall in last month too), so the
 * merchant's "Tax collected" isn't empty after Reset demo. Tax is worked out
 * with the rate in effect on each order's date. Every other seeded
 * redemption is a typed-in amount, which already includes any tax.
 *
 * B. A small ring at one merchant. The three PATTERN_B_EMPLOYEES each make a
 *    round $60 or $70 redemption at PATTERN_B_MERCHANT within ten minutes of
 *    each other, late on Thursday afternoons, on PATTERN_B_DAYS separate
 *    weeks. Each amount is normal for a salon; the same three people at the
 *    same place at the same minute, week after week, is not.
 */
class DemoHistorySeeder
{
    public const SEED = 20261014;

    public const WEEKS = 8;

    public const PATTERN_A_EMPLOYEE = 'Reese Okafor';

    public const PATTERN_A_MERCHANT = 'Triangle Stems';

    public const PATTERN_B_EMPLOYEES = ['Dana Whitfield', 'Marcus Lindqvist', 'Priya Raman'];

    public const PATTERN_B_MERCHANT = 'K Street Cuts';

    public const PATTERN_B_DAYS = 5;

    /** Who may be the seeded in-person example (ADR 0026), in order: never Jordan, never a pattern member. */
    public const IN_PERSON_EMPLOYEES = ['Morgan Ellery', 'Avery Holloway', 'Riley Nakamura', 'Drew Okonkwo'];

    public const IN_PERSON_MERCHANT = 'Farragut Grind';

    public const IN_PERSON_CENTS = 1000;

    /** Who may place the seeded menu orders with tax (ADR 0027), in order: never Jordan, never a pattern member. */
    public const TAXED_ORDER_EMPLOYEES = ['Alex Moreau', 'Taylor Brennan', 'Sam Achterberg', 'Casey Abernathy'];

    public const TAXED_ORDER_MERCHANT = 'Farragut Grind';

    /** [days ago (or 'last_month'), time, [product name => quantity]] */
    public const TAXED_ORDERS = [
        ['last_month', '09:05', ['Latte' => 2, 'Croissant' => 1]],
        [2, '08:15', ['Cappuccino' => 1]],
        [1, '12:40', ['Avocado toast' => 1, 'Cold brew' => 1]],
    ];

    /** Invented names. Any match with a real person is a coincidence. */
    public const NAMES = [
        'Reese Okafor', 'Dana Whitfield', 'Marcus Lindqvist', 'Priya Raman', 'Alex Moreau',
        'Taylor Brennan', 'Sam Achterberg', 'Jamie Castellanos', 'Morgan Ellery', 'Riley Nakamura',
        'Avery Holloway', 'Quinn Delacroix', 'Casey Abernathy', 'Jordan Pike', 'Robin Esposito',
        'Skyler Fairbanks', 'Drew Okonkwo', 'Parker Valdés', 'Hayden Marsh', 'Emerson Kowalczyk',
        'Rowan Sato', 'Finley Adebayo', 'Sage Lindgren', 'Blake Montague', 'Cameron Iqbal',
        'Elliot Brandt', 'Harper Quiñones', 'Kendall Osei', 'Logan Petrakis', 'Micah Thornbury',
        'Noel Vasquez', 'Oakley Duarte', 'Peyton Haldane', 'Reagan Mbeki', 'Shiloh Varga',
        'Toby Ainsworth', 'Wren Castellane', 'Zion Albright', 'Ari Kensington',
    ];

    private Randomizer $random;

    /**
     * @param  Collection<string, Merchant>  $merchants  keyed as in DatabaseSeeder
     */
    public function run(Employer $employer, Collection $merchants, Carbon $now): void
    {
        $this->random = new Randomizer(new Mt19937(self::SEED));
        $ledger = app(CreditLedger::class);
        $windowStart = $now->copy()->subWeeks(self::WEEKS)->startOfDay();
        $byName = $merchants->keyBy('name');

        foreach (self::NAMES as $name) {
            $employee = Employee::create(['employer_id' => $employer->id, 'display_name' => $name]);
            $allowances = [];

            for ($month = $windowStart->copy()->startOfMonth(); $month->lte($now); $month->addMonth()) {
                $at = $month->copy()->setTime(6, 0);
                $ledger->allocate($employer, $employee, $employer->monthly_allowance_cents, $at->copy()->utc());
                $allowances[] = $at;
            }

            $planted = match (true) {
                $name === self::PATTERN_A_EMPLOYEE => $this->patternA($byName[self::PATTERN_A_MERCHANT], $allowances, $windowStart, $now),
                in_array($name, self::PATTERN_B_EMPLOYEES, true) => $this->patternB($byName[self::PATTERN_B_MERCHANT], $name, $now),
                default => [],
            };
            $everyday = $name === self::PATTERN_A_EMPLOYEE ? [] : $this->everyday($merchants, $windowStart, $now, light: $planted !== []);

            $this->record($employee, $planted, $everyday, $allowances, $employer->monthly_allowance_cents);
        }

        $this->inPersonExample($byName[self::IN_PERSON_MERCHANT], $now);
        $this->taxedMenuOrders($byName[self::TAXED_ORDER_MERCHANT], $now);
    }

    /**
     * The three seeded menu orders with DC sales tax added, written like
     * every other seeded redemption (cleared, simulated) plus their items and
     * tax snapshot. An order is skipped if no listed employee can afford it.
     */
    private function taxedMenuOrders(Merchant $merchant, Carbon $now): void
    {
        $products = $merchant->products()->get()->keyBy('name');
        $ledger = app(CreditLedger::class);

        foreach (self::TAXED_ORDERS as [$daysAgo, $time, $picked]) {
            [$hour, $minute] = explode(':', $time);
            $day = $daysAgo === 'last_month' ? $now->copy()->startOfMonth()->subDays(10) : $now->copy()->subDays($daysAgo);
            $at = $day->setTime((int) $hour, (int) $minute)->utc();

            $tax = SalesTax::forItems(array_map(fn (string $name, int $quantity) => [
                'merchant_product_id' => $products[$name]->id, 'name' => $name, 'unit_price_cents' => $products[$name]->price_cents,
                'quantity' => $quantity, 'tax_category' => $products[$name]->tax_category,
            ], array_keys($picked), $picked), $at);

            $employee = Employee::whereIn('display_name', self::TAXED_ORDER_EMPLOYEES)->get()
                ->sortBy(fn (Employee $e) => array_search($e->display_name, self::TAXED_ORDER_EMPLOYEES, true))
                ->first(fn (Employee $e) => $this->canAfford($e, $at, $tax->totalCents));
            if ($employee === null) {
                continue;
            }

            $redemption = Redemption::create([
                'employee_id' => $employee->id,
                'merchant_id' => $merchant->id,
                'amount_cents' => $tax->totalCents,
                'status' => Redemption::STATUS_APPROVED,
                'scored_by' => 'seed',
                'created_at' => $at,
                ...$tax->columns(),
            ]);
            foreach ($tax->items as $item) {
                $redemption->items()->make($item)->forceFill(['created_at' => $at])->save();
            }
            CreditLedgerEntry::create([
                'employee_id' => $employee->id,
                'amount_cents' => -$tax->totalCents,
                'type' => CreditLedgerEntry::TYPE_REDEMPTION,
                'redemption_id' => $redemption->id,
                'description' => 'Redemption at '.$merchant->name,
                'created_at' => $at,
            ]);
            $ledger->creditMerchant($redemption, $at);
        }
    }

    /** Whether the employee's balance at $at, and at every point after it, still covers $cents. */
    private function canAfford(Employee $employee, Carbon $at, int $cents): bool
    {
        $running = (int) $employee->ledgerEntries()->where('created_at', '<=', $at)->sum('amount_cents');
        $lowest = $running;
        foreach ($employee->ledgerEntries()->where('created_at', '>', $at)->orderBy('created_at')->pluck('amount_cents') as $amount) {
            $lowest = min($lowest, $running += $amount);
        }

        return $cents <= $lowest;
    }

    /**
     * One completed in-person payment with its used pay code, so the demo
     * shows how paying at the counter looks afterwards. Skipped if it would
     * ever take the employee's balance below zero.
     */
    private function inPersonExample(Merchant $merchant, Carbon $now): void
    {
        $cents = self::IN_PERSON_CENTS;
        $at = $now->copy()->subDay()->setTime(8, 50)->utc();

        // The first who can afford it: the lowest their balance gets from then on must still cover it.
        $employee = Employee::whereIn('display_name', self::IN_PERSON_EMPLOYEES)->get()
            ->sortBy(fn (Employee $e) => array_search($e->display_name, self::IN_PERSON_EMPLOYEES, true))
            ->first(fn (Employee $e) => $this->canAfford($e, $at, $cents));
        if ($employee === null) {
            return;
        }

        $redemption = Redemption::create([
            'employee_id' => $employee->id,
            'merchant_id' => $merchant->id,
            'amount_cents' => $cents,
            'status' => Redemption::STATUS_APPROVED,
            'scored_by' => 'seed',
            'channel' => Redemption::CHANNEL_IN_PERSON,
            'created_at' => $at,
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
        app(CreditLedger::class)->creditMerchant($redemption, $at);

        $code = PayCodes::generate();
        $payCode = new PayCode([
            'employee_id' => $employee->id,
            'merchant_id' => $merchant->id,
            'amount_cents' => $cents,
            'code_hash' => PayCodes::hash($code),
            'code' => $code,
            'status' => PayCode::STATUS_USED,
            'expires_at' => $at->copy()->subMinute()->addMinutes(PayCode::EXPIRES_MINUTES),
            'used_at' => $at,
            'redemption_id' => $redemption->id,
            'tax_basis' => TaxBreakdown::INCLUDED,
        ]);
        $payCode->created_at = $at->copy()->subMinute();
        $payCode->updated_at = $at;
        $payCode->save();
    }

    /**
     * Writes one employee's redemptions in time order. Planted ones always go
     * in; an everyday one is skipped if it would leave too little for the
     * planted ones still to come, so no balance ever goes below zero.
     *
     * @param  list<array{0: Merchant, 1: int, 2: Carbon}>  $planted
     * @param  list<array{0: Merchant, 1: int, 2: Carbon}>  $everyday
     * @param  list<Carbon>  $allowances
     */
    private function record(Employee $employee, array $planted, array $everyday, array $allowances, int $allowanceCents): void
    {
        $events = array_merge(
            array_map(fn ($e) => [...$e, true], $planted),
            array_map(fn ($e) => [...$e, false], $everyday),
        );
        usort($events, fn ($a, $b) => $a[2] <=> $b[2]);

        $rows = [];

        foreach ($events as $i => [$merchant, $cents, $at, $isPlanted]) {
            $balance = $allowanceCents * count(array_filter($allowances, fn ($a) => $a->lte($at)))
                - array_sum(array_column($rows, 'amount_cents'));
            $plantedStillToCome = array_sum(array_map(fn ($e) => $e[3] ? $e[1] : 0, array_slice($events, $i + 1)));

            if (! $isPlanted && $cents > $balance - $plantedStillToCome) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'merchant_id' => $merchant->id,
                'amount_cents' => $cents,
                'status' => Redemption::STATUS_APPROVED,
                'scored_by' => 'seed',
                'created_at' => $at->copy()->utc(),
                'updated_at' => $at->copy()->utc(),
                'tax_basis' => TaxBreakdown::INCLUDED,
            ];
        }

        if ($rows === []) {
            return;
        }

        // Bulk inserts keep the seed fast enough to run in every test.
        DB::table('redemptions')->insert($rows);

        $names = Merchant::pluck('name', 'id');
        $entries = [];

        foreach (Redemption::where('employee_id', $employee->id)->orderBy('id')->get() as $redemption) {
            $common = ['redemption_id' => $redemption->id, 'created_at' => $redemption->created_at];
            $entries[] = $common + [
                'employee_id' => $employee->id, 'merchant_id' => null, 'amount_cents' => -$redemption->amount_cents,
                'type' => CreditLedgerEntry::TYPE_REDEMPTION, 'description' => 'Redemption at '.$names[$redemption->merchant_id],
            ];
            $entries[] = $common + [
                'employee_id' => null, 'merchant_id' => $redemption->merchant_id, 'amount_cents' => $redemption->amount_cents,
                'type' => CreditLedgerEntry::TYPE_MERCHANT_EARNED, 'description' => 'Cleared redemption of $'.number_format($redemption->amount_cents / 100, 2),
            ];
        }

        foreach (array_chunk($entries, 200) as $chunk) {
            DB::table('credit_ledger_entries')->insert($chunk);
        }
    }

    /**
     * Pattern A: the day after each allowance, $80 then $60 at one merchant.
     *
     * @param  list<Carbon>  $allowances
     * @return list<array{0: Merchant, 1: int, 2: Carbon}>
     */
    private function patternA(Merchant $merchant, array $allowances, Carbon $windowStart, Carbon $now): array
    {
        $events = [];

        foreach ($allowances as $allowance) {
            $day = $allowance->copy()->addDay()->max($windowStart);
            $first = $day->copy()->setTime(19, 20 + $this->random->getInt(0, 15));
            $second = $first->copy()->addMinutes($this->random->getInt(20, 40));

            if ($second->lt($now)) {
                $events[] = [$merchant, 8000, $first];
                $events[] = [$merchant, 6000, $second];
            }
        }

        return $events;
    }

    /**
     * Pattern B: on PATTERN_B_DAYS recent Thursdays, each ring member within
     * ten minutes of 5:40 PM at the same merchant.
     *
     * @return list<array{0: Merchant, 1: int, 2: Carbon}>
     */
    private function patternB(Merchant $merchant, string $name, Carbon $now): array
    {
        $offset = array_search($name, self::PATTERN_B_EMPLOYEES, true) * 3;
        $thursday = $now->copy()->subDay()->previous(Carbon::THURSDAY);
        $events = [];

        for ($week = 0; $week < self::PATTERN_B_DAYS; $week++) {
            $at = $thursday->copy()->subWeeks($week)->setTime(17, 40 + $offset + $this->random->getInt(0, 2));
            $events[] = [$merchant, $this->random->getInt(0, 1) ? 6000 : 7000, $at];
        }

        return $events;
    }

    /**
     * Ordinary spending: a few favourite merchants, weekday business hours,
     * amounts inside each category's usual range and rarely round. Ring
     * members spend lightly so the planted redemptions always fit.
     *
     * @param  Collection<string, Merchant>  $merchants
     * @return list<array{0: Merchant, 1: int, 2: Carbon}>
     */
    private function everyday(Collection $merchants, Carbon $windowStart, Carbon $now, bool $light): array
    {
        $typical = [
            'cafe' => [350, 950], 'restaurant' => [1100, 2600], 'retail' => [1200, 4800],
            'salon' => [3000, 7500], 'fitness' => [2000, 3600],
        ];
        $hours = ['cafe' => [7, 11], 'restaurant' => [11, 14], 'retail' => [12, 18], 'salon' => [12, 18], 'fitness' => [7, 9]];

        $favourites = $this->random->pickArrayKeys($merchants->all(), $light ? 1 : $this->random->getInt(2, 4));
        $perWeek = $light ? 1 : $this->random->getInt(1, 4);
        $events = [];

        for ($day = $windowStart->copy(); $day->lt($now); $day->addWeek()) {
            for ($n = 0; $n < $perWeek; $n++) {
                $merchant = $merchants[$favourites[$this->random->getInt(0, count($favourites) - 1)]];
                $category = $light ? 'cafe' : $merchant->category;
                $merchant = $light ? $merchants['cafe'] : $merchant;
                [$open, $close] = $hours[$category];
                $at = $day->copy()->addDays($this->random->getInt(0, 4))
                    ->setTime($this->random->getInt($open, $close - 1), $this->random->getInt(0, 59));

                if ($at->isWeekend() || $at->gte($now)) {
                    continue;
                }

                [$min, $max] = $typical[$category];
                $cents = intdiv($this->random->getInt($min, $max), 5) * 5;
                $events[] = [$merchant, $cents % 1000 === 0 ? $cents + 45 : $cents, $at];
            }
        }

        return $events;
    }
}
