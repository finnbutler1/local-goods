<?php

namespace Tests\Unit;

use App\Support\WilsonInterval;
use PHPUnit\Framework\TestCase;

class WilsonIntervalTest extends TestCase
{
    public function test_matches_published_wilson_intervals(): void
    {
        // Reference values from the standard Wilson score formula (z = 1.96).
        $this->assertSame([23.7, 76.3], WilsonInterval::percent(5, 10));
        $this->assertSame([49.0, 94.3], WilsonInterval::percent(8, 10));
        $this->assertSame([0.0, 27.8], WilsonInterval::percent(0, 10));
        $this->assertSame([72.2, 100.0], WilsonInterval::percent(10, 10));
    }

    public function test_an_empty_denominator_has_no_interval(): void
    {
        $this->assertNull(WilsonInterval::percent(0, 0));
    }
}
