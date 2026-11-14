<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Services\Solvency\LedgerTranches;
use App\Services\Solvency\SolvencyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The two tranches (ADR 0033) cover every cent of the ledger with no
 * overlap: every entry type is classified once, every line in the seeded
 * and generated history sits on the account its type says, and the
 * tranches add up to the whole ledger.
 */
class LedgerTranchesTest extends TestCase
{
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    public function test_every_entry_type_is_classified_exactly_once(): void
    {
        $types = collect((new ReflectionClass(CreditLedgerEntry::class))->getConstants())
            ->filter(fn ($v, $name) => str_starts_with($name, 'TYPE_'))->values()->sort()->values()->all();

        $classified = array_keys(LedgerTranches::ACCOUNT_OF_TYPE);
        sort($classified);
        $this->assertSame($types, $classified, 'Every ledger entry type has one tranche, and nothing else is listed.');

        foreach ($types as $type) {
            $this->assertContains(LedgerTranches::trancheOf($type), [LedgerTranches::CUSTOMER, LedgerTranches::OPERATING]);
        }
        $this->assertSame(
            [CreditLedgerEntry::TYPE_PLATFORM_FEE, CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE, CreditLedgerEntry::TYPE_BID_PROGRAM_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED,
                CreditLedgerEntry::TYPE_OPERATING_SWEEP, CreditLedgerEntry::TYPE_OPERATING_SWEEP_RETURNED],
            array_keys(array_filter(LedgerTranches::ACCOUNT_OF_TYPE, fn ($a) => $a === LedgerTranches::PLATFORM)),
            'Only fee lines, and the sweeps that move earned fees to Local Goods Operating (ADR 0034), are operating funds.'
        );
        $this->assertSame(LedgerTranches::SWEEP_TYPES, array_values(array_intersect(LedgerTranches::MONEY_IN_HAND_TYPES, LedgerTranches::SWEEP_TYPES)),
            'A sweep moves money out of the customer funds account.');
    }

    public function test_an_unknown_type_is_refused_rather_than_guessed(): void
    {
        $this->expectException(\LogicException::class);
        LedgerTranches::trancheOf('mystery');
    }

    public function test_seeded_and_generated_lines_are_each_in_one_tranche_and_add_up_to_the_whole_ledger(): void
    {
        $this->startDemoActivity();
        $this->runDay('2026-10-26');

        $lines = DB::table('credit_ledger_entries')->get();
        $this->assertGreaterThan(1000, $lines->count());

        $byTranche = [LedgerTranches::CUSTOMER => 0, LedgerTranches::OPERATING => 0];
        foreach ($lines as $line) {
            $accounts = array_keys(array_filter([
                LedgerTranches::EMPLOYEE => $line->employee_id !== null,
                LedgerTranches::EMPLOYER => $line->employer_id !== null,
                LedgerTranches::MERCHANT => $line->merchant_id !== null,
                LedgerTranches::PLATFORM => (bool) $line->platform,
            ]));
            $this->assertCount(1, $accounts, "Line {$line->id} is on exactly one account.");
            $this->assertSame(LedgerTranches::accountOf($line->type), $accounts[0], "Line {$line->id} ({$line->type}) is on its type's account.");
            $byTranche[LedgerTranches::trancheOf($line->type)] += (int) $line->amount_cents;
        }

        $this->assertSame((int) $lines->sum('amount_cents'), array_sum($byTranche), 'The tranches add up to every cent in the ledger.');

        // The same split from the check: customer funds plus operating funds are everything promised.
        $now = app(SolvencyCheck::class)->now();
        $this->assertSame([], $now->negativeAccounts);
        $this->assertSame($byTranche[LedgerTranches::OPERATING], $now->operatingCents);
        $this->assertSame($byTranche[LedgerTranches::CUSTOMER] + $now->heldPendingCents(), $now->customerFundsCents(),
            'Customer funds are the customer lines plus what is held pending a decision (already taken off the employee or employer).');
    }
}
