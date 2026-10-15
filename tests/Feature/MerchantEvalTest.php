<?php

namespace Tests\Feature;

use App\Models\MerchantReviewDecision;
use App\Services\Ai\ClaudeClient;
use App\Services\Eligibility\EligibilityAssessment;
use App\Services\Eligibility\MerchantEligibilityMatcher;
use App\Services\Eligibility\MerchantEvalRunner;
use App\Services\Eligibility\MerchantLabelLock;
use App\Services\Eligibility\MerchantLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * merchant:eval against 60 fictional, labeled candidates in a temporary
 * folder (never the real label file). The fake AI answers each business by
 * its number, so every metric can be checked by hand.
 */
class MerchantEvalTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private FakeClaudeClient $fake;

    /** @var list<int> businesses whose AI call fails */
    private array $failing = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/lg-merchant-eval-'.uniqid();
        mkdir($this->dir);
        config([
            'eligibility.candidates_path' => "{$this->dir}/candidates.csv",
            'eligibility.activity_path' => "{$this->dir}/activity.csv",
            'eligibility.labels_path' => "{$this->dir}/labels.csv",
            'eligibility.labels_lock_path' => "{$this->dir}/lock.json",
            'eligibility.eval_dir' => "{$this->dir}/evals",
            'eligibility.eval_latest_path' => "{$this->dir}/evals/merchant-eval-latest.json",
            'eligibility.eval_cache_path' => "{$this->dir}/cache",
            'eligibility.assessments_path' => "{$this->dir}/assessments.json",
        ]);

        $candidates = $labels = $activity = [];
        foreach (range(1, 60) as $n) {
            $id = sprintf('MC-%03d', $n);
            $name = sprintf('Example Business %02d', $n);
            [$label, $reason] = $this->label($n);
            $candidates[] = [$id, $name, "{$n} K ST NW, WASHINGTON, DC, 20006", 'cafe', 'https://example.test/'.$n];
            $activity[] = [$id, 'Restaurant'];
            $labels[] = [$id, $name, "{$n} K ST NW, WASHINGTON, DC, 20006", 'cafe', $label, $reason, "https://example.test/evidence/{$n}", "Private note {$n}", $n <= 40 ? 'dev' : 'holdout'];
        }
        $this->writeCsv('candidates.csv', ['candidate_id', 'name', 'address', 'category', 'source_url'], $candidates);
        $this->writeCsv('activity.csv', ['candidate_id', 'business_activity'], $activity);
        $this->writeCsv('labels.csv', MerchantLabels::COLUMNS, $labels);
        file_put_contents("{$this->dir}/lock.json", json_encode(MerchantLabelLock::build(MerchantLabels::load(), str_repeat('c', 40), '2026-10-15T12:00:00-04:00')));

        $this->fake = new FakeClaudeClient(function (string $user) {
            $n = (int) substr(json_decode($user, true)['name'], -2);

            return in_array($n, $this->failing, true) ? new RuntimeException('timeout') : $this->answer($n);
        });
        $this->app->instance(ClaudeClient::class, $this->fake);
    }

    /** Dev 1–10 independent, 11–25 franchise, 26–35 local chain, 36–40 national chain; holdout the same pattern from 41. */
    private function label(int $n): array
    {
        $k = ($n - 1) % 40 + 1;

        return match (true) {
            $k <= 10 => ['independent', ''],
            $k <= 25 => ['not_independent', 'franchise'],
            $k <= 35 => ['not_independent', 'local_chain'],
            default => ['not_independent', 'national_chain'],
        };
    }

    /** Right on 1–6, undetermined on 7–9, franchise on 10; independent on 23, undetermined on 24–25; national chain on 31–35. */
    private function answer(int $n): string
    {
        $class = match (true) {
            $n <= 6 => 'independent',
            $n <= 9 => 'undetermined',
            $n <= 22 => 'franchise',
            $n === 23 => 'independent',
            $n <= 25 => 'undetermined',
            $n <= 30 => 'local_chain',
            default => 'national_chain',
        };

        return json_encode([
            'eligible' => $class === 'independent', 'classification' => $class, 'confidence' => 0.8,
            'reasoning' => "Reasoning for business {$n}.", 'needs_human_review' => $class === 'undetermined',
        ]);
    }

    private function writeCsv(string $file, array $header, array $rows): void
    {
        $handle = fopen("{$this->dir}/{$file}", 'w');
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
    }

    private function latest(): array
    {
        return json_decode(file_get_contents("{$this->dir}/evals/merchant-eval-latest.json"), true);
    }

    public function test_dev_is_scored_with_every_metric(): void
    {
        $this->artisan('merchant:eval')->assertSuccessful();

        $this->assertCount(40, $this->fake->calls);
        $s = $this->latest()['splits']['dev']['summary'];
        $this->assertNull($this->latest()['splits']['holdout']);

        $this->assertSame([35, 40], [$s['agreement']['hits'], $s['agreement']['of']]);
        $this->assertSame([30, 40], [$s['baseline_always_not_eligible']['hits'], $s['baseline_always_not_eligible']['of']]);
        $this->assertSame([6, 10], [$s['recall']['hits'], $s['recall']['of']]);
        $this->assertSame([6, 7], [$s['precision']['hits'], $s['precision']['of']]);
        $this->assertSame([28, 40], [$s['class_match']['hits'], $s['class_match']['of']]);
        $this->assertSame(['eligible' => 6, 'not_eligible' => 1, 'undetermined' => 3], $s['matrix']['independent']);
        $this->assertSame(['eligible' => 1, 'not_eligible' => 27, 'undetermined' => 2], $s['matrix']['not_independent']);
        $this->assertSame(15, $s['by_fail_reason']['franchise']['cases']);
        $this->assertSame([12, 12, 2, 1], [
            $s['by_fail_reason']['franchise']['not_eligible']['hits'], $s['by_fail_reason']['franchise']['same_reason']['hits'],
            $s['by_fail_reason']['franchise']['undetermined'], $s['by_fail_reason']['franchise']['called_eligible'],
        ]);
        $this->assertSame([10, 5], [$s['by_fail_reason']['local_chain']['not_eligible']['hits'], $s['by_fail_reason']['local_chain']['same_reason']['hits']]);
        $this->assertSame(0, $s['by_fail_reason']['outside_control']['cases']);
        $this->assertSame([5, 40], [$s['undetermined']['ai_undetermined_on_decided']['hits'], $s['undetermined']['ai_undetermined_on_decided']['of']]);
        $this->assertSame(0, $s['undetermined']['human_undetermined']);
        $this->assertSame(12, $s['disagreements']);
        $this->assertSame([27, 30], [$s['triage']['chains_not_eligible']['hits'], $s['triage']['chains_not_eligible']['of']]);
        $this->assertSame([1, 30], [$s['triage']['chains_called_eligible']['hits'], $s['triage']['chains_called_eligible']['of']]);
        $this->assertSame([5, 40], [$s['triage']['routed_to_review']['hits'], $s['triage']['routed_to_review']['of']]);
        $this->assertSame(5, $s['category_disagreements']); // 31–35: local chain called national chain
        $this->assertNotNull($s['recall']['ci95']);
    }

    public function test_the_report_lists_disagreements_with_finns_note_and_the_ai_reasoning(): void
    {
        $this->artisan('merchant:eval')->assertSuccessful();

        $md = file_get_contents("{$this->dir}/evals/merchant-eval-results.md");
        $this->assertStringContainsString('candidate, not a Local Goods participant', $md);
        $this->assertStringContainsString('did not search the web', $md);
        $this->assertStringContainsString('Baseline: always answer "not eligible" | 30 of 40', $md);
        $this->assertStringContainsString('Private note 23', $md);
        $this->assertStringContainsString('Reasoning for business 23.', $md);
        $this->assertStringContainsString('**MC-023 Example Business 23**', $md);
        $this->assertStringNotContainsString('Private note 1.', $md, 'Agreed cases are not listed.');
        $this->assertStringContainsString('Triage: chains and franchises wrongly called eligible | 1 of 30', $md);
        $this->assertStringContainsString('Category disagreements (5)', $md);
        $this->assertStringContainsString('labels stay locked and unchanged', $md);
        $this->assertStringContainsString('MC-031 Example Business 31 (Finn: Local chain, AI: National chain)', $md);
        $this->assertStringContainsString('## Holdout', $md);
        $this->assertStringContainsString('Not run yet.', $md);
    }

    public function test_the_assessments_file_holds_ai_answers_only(): void
    {
        $this->artisan('merchant:eval')->assertSuccessful();

        $raw = file_get_contents("{$this->dir}/assessments.json");
        $saved = json_decode($raw, true)['assessments'];
        $this->assertCount(40, $saved);
        $this->assertSame('independent', $saved['MC-001']['classification']);
        $this->assertSame(MerchantEligibilityMatcher::promptVersion(), $saved['MC-001']['prompt_version']);
        foreach (['my_label', 'fail_reason', 'my_note', 'evidence', 'Private note', 'not_independent'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function test_an_eval_run_approves_nothing(): void
    {
        $this->artisan('merchant:eval')->assertSuccessful();
        $this->artisan('merchant:eval --split=holdout')->assertSuccessful();

        $this->assertSame(0, MerchantReviewDecision::count(), 'The eval wrote a decision; only a person may.');
    }

    public function test_the_holdout_runs_only_with_the_frozen_prompt(): void
    {
        config(['eligibility.frozen_prompt_version' => 'ffffffff']);

        $this->artisan('merchant:eval --split=holdout')->expectsOutputToContain('only with the frozen prompt')->assertFailed();
        $this->assertCount(0, $this->fake->calls);
    }

    public function test_a_rerun_uses_the_cache_and_does_not_rebill(): void
    {
        $this->artisan('merchant:eval')->assertSuccessful();
        $this->artisan('merchant:eval')->expectsOutputToContain('0 new API call(s)')->assertSuccessful();

        $this->assertCount(40, $this->fake->calls);
    }

    public function test_a_dry_run_calls_nothing_and_saves_nothing(): void
    {
        $this->artisan('merchant:eval --dry-run')->expectsOutputToContain('40 new API call(s)')->assertSuccessful();

        $this->assertCount(0, $this->fake->calls);
        $this->assertDirectoryDoesNotExist("{$this->dir}/evals");
    }

    public function test_it_refuses_to_run_when_a_label_changed_after_the_lock(): void
    {
        file_put_contents("{$this->dir}/labels.csv", str_replace('Private note 7', 'Private note 7 (edited)', file_get_contents("{$this->dir}/labels.csv")));

        $this->artisan('merchant:eval')->expectsOutputToContain('MC-007: changed since it was locked')->assertFailed();
        $this->artisan('merchant:eval --dry-run')->assertFailed();

        $this->assertCount(0, $this->fake->calls);
    }

    public function test_it_refuses_to_run_without_a_lock(): void
    {
        unlink("{$this->dir}/lock.json");

        $this->artisan('merchant:eval')->expectsOutputToContain('not locked')->assertFailed();
        $this->assertCount(0, $this->fake->calls);
    }

    public function test_the_holdout_is_scored_once_per_prompt_version(): void
    {
        $this->artisan('merchant:eval --split=holdout')->assertSuccessful();
        $this->assertCount(20, $this->fake->calls);
        $this->assertSame([MerchantEligibilityMatcher::promptVersion()], $this->latest()['holdout_seen_by']);

        $this->artisan('merchant:eval --split=holdout')->expectsOutputToContain('already scored the holdout')->assertFailed();
        $this->artisan('merchant:eval --split=holdout --dry-run')->assertFailed();
        $this->assertCount(20, $this->fake->calls);

        // Dev and holdout stay separate.
        $this->travel(1)->minutes();
        $this->artisan('merchant:eval')->assertSuccessful();
        $latest = $this->latest();
        $this->assertSame(20, $latest['splits']['holdout']['summary']['cases']);
        $this->assertSame(40, $latest['splits']['dev']['summary']['cases']);
        $this->assertSame(['holdout', 'dev'], array_column($latest['history'], 'split'));
    }

    public function test_a_failed_ai_call_saves_nothing_and_is_retried_alone(): void
    {
        $this->failing = [7];

        $this->artisan('merchant:eval')->expectsOutputToContain('MC-007')->assertFailed();
        $this->assertDirectoryDoesNotExist("{$this->dir}/evals");
        $this->assertFileDoesNotExist("{$this->dir}/assessments.json");

        $this->failing = [];
        $this->artisan('merchant:eval')->expectsOutputToContain('1 new API call(s)')->assertSuccessful();
        $this->assertCount(41, $this->fake->calls);
    }

    public function test_an_undetermined_label_counts_whether_the_ai_also_said_undetermined(): void
    {
        $case = fn (string $id) => ['candidate_id' => $id, 'split' => 'dev', 'name' => 'X', 'address' => 'Y', 'category' => 'cafe', 'business_activity' => '',
            'my_label' => 'undetermined', 'fail_reason' => '', 'evidence_url' => '', 'my_note' => ''];
        $answer = fn (string $class) => new EligibilityAssessment($class === 'independent', $class, 0.5, 'r', true, 'm');

        $s = MerchantEvalRunner::summarize([
            MerchantEvalRunner::score($case('MC-1'), $answer('undetermined')),
            MerchantEvalRunner::score($case('MC-2'), $answer('independent')),
        ]);

        $this->assertSame([1, 2], [$s['undetermined']['ai_also_undetermined']['hits'], $s['undetermined']['ai_also_undetermined']['of']]);
        $this->assertSame(1, $s['class_match']['hits']);
    }
}
