<?php

namespace Tests\Unit;

use App\Support\SignalPresenter;
use Tests\TestCase;

/** The fraud review page's signal labels say what the code checks (display text only). */
class SignalPresenterTest extends TestCase
{
    public function test_the_round_amount_label_matches_the_config_the_signal_uses(): void
    {
        $this->assertSame('Round amount (a multiple of $10, and at least $20)', SignalPresenter::rows(['is_round_number' => true])[0]['label']);

        config(['fraud.round_number_step_cents' => 500, 'fraud.round_number_min_cents' => 2550]);
        $this->assertSame('Round amount (a multiple of $5, and at least $25.50)', SignalPresenter::roundAmountLabel());
    }

    public function test_the_old_vague_label_is_gone(): void
    {
        $labels = array_column(SignalPresenter::rows([]), 'label');

        $this->assertNotContains('Round number', $labels);
    }
}
