<?php

namespace App\Services\Fraud;

use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\RedemptionService;
use Database\Seeders\DemoHistorySeeder;
use InvalidArgumentException;

/**
 * "Run a collusion example" on the fraud review page (ADR 0016, admins only).
 * Makes one fresh redemption with the credit-to-cash shape at Farragut
 * Grind: a round amount using most of a synthetic employee's balance, far
 * above a café's usual ticket. It goes through the normal redemption path,
 * so the real Redemption Fraud Detection check scores it and the real
 * TransferGate decides about money. Whatever the AI answers is what the page
 * shows: if it clears, that is an honest miss.
 *
 * Only the seeded synthetic employees (DemoHistorySeeder::NAMES), never
 * Jordan Rivera, whose history other demos depend on. No people, credits or
 * allocations are created: it spends a balance the seed already gave.
 */
class CollusionExample
{
    /** The smallest example: $20 is where "round number" starts. */
    public const MIN_CENTS = 2000;

    public function __construct(private RedemptionService $redemptions) {}

    public function run(): Redemption
    {
        $merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->first()
            ?? throw new InvalidArgumentException('The demo merchant is missing. Press Reset demo first.');

        $step = (int) config('fraud.round_number_step_cents');

        $employee = Employee::whereIn('display_name', DemoHistorySeeder::NAMES)->orderBy('id')->get()
            ->map(fn (Employee $e) => [$e, $e->balanceCents()])
            ->filter(fn (array $pair) => $pair[1] >= self::MIN_CENTS)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->first();

        if ($employee === null) {
            throw new InvalidArgumentException('No synthetic employee has '.(self::MIN_CENTS / 100).' dollars left for an example. Press Reset demo first.');
        }

        [$employee, $balance] = $employee;

        return $this->redemptions->redeem($employee, $merchant, intdiv($balance, $step) * $step);
    }
}
