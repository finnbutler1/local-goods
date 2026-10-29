<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The README and the /eval page must state the same catch counts as the
 * locked holdout_3 results, as exact counts and not as "about two of every
 * three". The counts come from the committed results file and the label
 * lock; nothing here is typed in by hand.
 */
class FraudCatchCountsMatchLockTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{cases: int, catch_hits: int, catch_of: int, hold_hits: int, hold_of: int, headline: string} */
    private function locked(): array
    {
        $run = json_decode(file_get_contents(base_path('docs/evals/fraud-eval-latest.json')), true, flags: JSON_THROW_ON_ERROR);
        $holdout = $run['summary']['holdout_3'];

        return [
            'cases' => $holdout['cases'],
            'catch_hits' => $holdout['catch']['hits'], 'catch_of' => $holdout['catch']['of'],
            'hold_hits' => $holdout['hold_precision']['hits'], 'hold_of' => $holdout['hold_precision']['of'],
            'headline' => $run['headline']['text'],
        ];
    }

    private function readmeSection(): string
    {
        $readme = file_get_contents(base_path('README.md'));
        $this->assertSame(1, preg_match('/^## Which error we chose to minimize\R(.*?)(?=^## )/ms', $readme, $m));

        return $m[1];
    }

    public function test_the_results_file_agrees_with_the_label_lock(): void
    {
        $lock = json_decode(file_get_contents(base_path('data/fraud_eval_lock.json')), true, flags: JSON_THROW_ON_ERROR);
        $locked = $this->locked();

        $this->assertSame('holdout_3', $lock['split']);
        $this->assertSame($lock['case_count'], $locked['cases']);
        $this->assertSame($lock['labels']['suspicious'], $locked['catch_of'], 'suspicious cases in the lock = the catch denominator');
    }

    public function test_the_readme_states_the_exact_counts(): void
    {
        $locked = $this->locked();
        $section = $this->readmeSection();

        $this->assertStringContainsString("caught {$locked['catch_hits']} of the {$locked['catch_of']} suspicious redemptions", $section);
        $this->assertStringContainsString("{$locked['hold_hits']} of the {$locked['hold_of']} redemptions it held were truly suspicious", $section);
        $this->assertDoesNotMatchRegularExpression('/two of every three|two-thirds|two out of three|almost every hold/i', $section);
    }

    public function test_the_eval_page_states_the_same_counts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $locked = $this->locked();

        $this->assertSame("Caught {$locked['catch_hits']} of {$locked['catch_of']} suspicious cases; {$locked['hold_hits']} of {$locked['hold_of']} holds were suspicious.", $locked['headline']);
        $this->get('/eval')->assertOk()->assertSee($locked['headline'], false);
    }
}
