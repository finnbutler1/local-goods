<?php

namespace Database\Seeders;

use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\CreditLedger;
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
