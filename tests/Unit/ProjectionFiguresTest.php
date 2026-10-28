<?php

namespace Tests\Unit;

use App\Support\Projections\Figures;
use PHPUnit\Framework\TestCase;

/** How projection figures are written (ADR 0030): rounded, with a real minus sign. */
class ProjectionFiguresTest extends TestCase
{
    public function test_dollars_round_to_thousands_and_millions(): void
    {
        $this->assertSame('$612', Figures::dollars(612.4));
        $this->assertSame('$28k', Figures::dollars(28386));
        $this->assertSame('−$198k', Figures::dollars(-198224));
        $this->assertSame('$1.83M', Figures::dollars(1828029));
        $this->assertSame('$1.00M', Figures::dollars(999_600));
        $this->assertSame('$0', Figures::dollars(0.2));
        $this->assertSame('n/a', Figures::dollars(null));
    }

    public function test_exact_dollars_keep_cents_only_when_there_are_any(): void
    {
        $this->assertSame('$6', Figures::exact(6));
        $this->assertSame('$13.54', Figures::exact(13.54));
        $this->assertSame('−$1.33', Figures::exact(-1.33));
    }

    public function test_percent_trims_zeros_but_never_from_whole_numbers(): void
    {
        $this->assertSame('85%', Figures::percent(0.85));
        $this->assertSame('1.5%', Figures::percent(0.015));
        $this->assertSame('0.25%', Figures::percent(0.0025));
        $this->assertSame('80%', Figures::percent(0.8002, 0));
        $this->assertSame('100%', Figures::percent(1.0));
        $this->assertSame('100%', Figures::percent(1.0, 0));
        $this->assertSame('10%', Figures::percent(0.1, 0));
    }

    public function test_assumptions_are_written_by_unit(): void
    {
        $this->assertSame('1.5%', Figures::assumption(['unit' => 'share of each sale', 'base' => 0.015], 'base'));
        $this->assertSame('$2,500', Figures::assumption(['unit' => 'dollars once per BID', 'base' => 2500], 'base'));
        $this->assertSame('4.33', Figures::assumption(['unit' => 'payouts per month', 'base' => 4.33], 'base'));
        $this->assertSame('24', Figures::assumption(['unit' => 'months', 'base' => 24], 'base'));
    }
}
