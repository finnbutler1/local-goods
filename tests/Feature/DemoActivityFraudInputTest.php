<?php

namespace Tests\Feature;

use App\Models\DemoActivityAction;
use App\Services\Fraud\SignalCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * A generated redemption (ADR 0032) is scored exactly like any other:
 * Redemption Fraud Detection sees only the usual anonymous signals, and
 * nothing says it was generated, planned or meant to look unusual.
 */
class DemoActivityFraudInputTest extends TestCase
{
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    public function test_the_ai_sees_only_the_usual_signals(): void
    {
        $this->startDemoActivity();

        $this->runDay('2026-10-26');

        $actions = DemoActivityAction::where('kind', 'redemption')->where('status', 'done')->with('redemption')->orderBy('id')->get();
        $this->assertCount($actions->count(), $this->claude->calls);
        $this->assertTrue($actions->contains('unusual', true), 'The day has an unusual slot');

        $usual = app(SignalCalculator::class)->fromFacts(1000, 5000, 3, 900, 1, 2, 'cafe', 12);

        foreach ($actions as $i => $action) {
            $sent = json_decode($this->claude->calls[$i]['user'], true);
            $this->assertSame($action->redemption->signals, $sent, $action->slot);
            $this->assertSame(array_keys($usual), array_keys($sent), 'The same keys as any redemption, no more');
            foreach (['demo', 'generat', 'unusual', 'slot', 'planned', 'activity'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $this->claude->calls[$i]['user'], $action->slot);
            }
        }
    }
}
