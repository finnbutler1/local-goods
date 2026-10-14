<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use App\Services\Fraud\FraudEvalLock;
use App\Services\Fraud\FraudEvalPatterns;
use App\Services\Fraud\FraudEvalRunner;
use App\Services\Fraud\SignalCalculator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class FraudEvalTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'case_id,merchant,category,amount,local_time,balance_before,recent_history,visits_7d,visits_30d,past_redemptions,median_past_amount,my_label,notes,split';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/fraud-eval-test-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        // The real lock covers the real cases file; test files start unlocked.
        config(['fraud.eval_lock_path' => "{$this->dir}/lock.json"]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function writeCases(string ...$rows): string
    {
        $path = "{$this->dir}/cases.csv";
        file_put_contents($path, implode("\n", [self::HEADER, ...$rows])."\n");

        return $path;
    }

    private function cases(): string
    {
        return $this->writeCases(
            'FE-01,Farragut Grind,cafe,6.50,08:40,120.00,"x",1,2,12,13.87,normal,coffee,dev',
            'FE-02,Triangle Stems,retail,150.00,23:40,150.00,"x",0,1,12,13.87,suspicious,drains balance,dev',
            'FE-03,Press Room Deli,restaurant,12.00,12:00,90.00,"x",1,1,12,13.87,normal,lunch,holdout',
            'FE-04,K Street Cuts,salon,85.00,18:30,88.00,"x",0,0,0,,suspicious,new and drains,holdout',
        );
    }

    private function useFake(FakeClaudeClient $fake): FakeClaudeClient
    {
        $this->app->instance(ClaudeClient::class, $fake);

        return $fake;
    }

    private function evalCommand(): array
    {
        return [
            '--cases' => "{$this->dir}/cases.csv",
            '--out' => "{$this->dir}/out",
            '--cache' => "{$this->dir}/cache",
        ];
    }

    public function test_csv_facts_give_the_same_signals_as_the_live_database_path(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();

        $live = app(SignalCalculator::class)->calculate(Employee::firstOrFail(), $deli, 12000);

        // The seeded employee: $120 balance, 12 past redemptions, median $13.87, deli 1 visit in 7 and 30 days.
        $case = ['amount' => '120.00', 'balance_before' => '120.00', 'past_redemptions' => '12', 'median_past_amount' => '13.87',
            'visits_7d' => '1', 'visits_30d' => '1', 'category' => 'restaurant', 'local_time' => '12:00'];

        $this->assertSame($live, app(FraudEvalRunner::class)->signalsFor($case));
    }

    public function test_unlabeled_or_invalid_rows_are_rejected_before_any_ai_call(): void
    {
        $fake = $this->useFake(FakeClaudeClient::verdict(5, 'clear'));
        $this->writeCases(
            'FE-01,Farragut Grind,cafe,6.50,08:40,120.00,"x",1,2,12,13.87,,,dev',
            'FE-02,Farragut Grind,bakery,6.50,8am,120.00,"x",5,2,1,13.87,normal,x,test',
        );

        $this->artisan('fraud:eval', $this->evalCommand())
            ->expectsOutputToContain('FE-01: my_label must be normal or suspicious')
            ->expectsOutputToContain('FE-02: unknown category bakery')
            ->expectsOutputToContain('FE-02: split must be dev, holdout, holdout_2 or holdout_3')
            ->assertFailed();

        $this->assertSame([], $fake->calls);
    }

    public function test_hold_counts_as_suspicious_and_agreement_is_split_by_dev_and_holdout(): void
    {
        // The fake always says hold, so it agrees only with the suspicious cases.
        $this->useFake(FakeClaudeClient::verdict(85, 'hold', 'Round full-balance redemption late at night.'));
        $this->cases();

        $this->artisan('fraud:eval', $this->evalCommand())
            ->expectsOutputToContain('Dev: 50% agreement (1/2)')
            ->expectsOutputToContain('Holdout: 50% agreement (1/2)')
            ->expectsOutputToContain('Disagreements: 2')
            ->expectsOutputToContain('You:  normal: coffee')
            ->expectsOutputToContain('AI:   hold (85): Round full-balance redemption late at night.')
            ->assertSuccessful();

        $latest = json_decode(file_get_contents("{$this->dir}/out/fraud-eval-latest.json"), true);
        $this->assertSame('claude-opus-5-5', $latest['model']);
        $this->assertSame(FraudEvalRunner::promptVersion(), $latest['prompt_version']);
        $this->assertSame(['normal' => ['normal' => 0, 'suspicious' => 1], 'suspicious' => ['normal' => 0, 'suspicious' => 1]], $latest['summary']['dev']['matrix']);
        $this->assertSame(50.0, $latest['summary']['all']['agreement_pct']);

        $md = file_get_contents("{$this->dir}/out/fraud-eval-results.md");
        $this->assertStringContainsString('claude-opus-5-5', $md);
        $this->assertStringContainsString('| FE-01 | dev |', $md);
        $this->assertCount(1, File::glob("{$this->dir}/out/runs/*.md"));
    }

    public function test_a_rerun_uses_the_cache_and_makes_no_api_calls(): void
    {
        $fake = $this->useFake(FakeClaudeClient::verdict(10, 'clear'));
        $this->cases();

        $this->artisan('fraud:eval', $this->evalCommand())->expectsOutputToContain('new API calls: 4')->assertSuccessful();
        $this->artisan('fraud:eval', $this->evalCommand())->expectsOutputToContain('new API calls: 0')->assertSuccessful();

        $this->assertCount(4, $fake->calls);
        $this->assertStringNotContainsString('Farragut', $fake->calls[0]['user'], 'Only anonymous signals go to the AI.');
    }

    public function test_failed_ai_calls_are_not_cached_or_counted_and_nothing_is_saved(): void
    {
        $this->useFake(new FakeClaudeClient(new RuntimeException('timeout')));
        $this->cases();

        $this->artisan('fraud:eval', $this->evalCommand())
            ->expectsOutputToContain('4 case(s) could not be scored')
            ->assertFailed();

        $this->assertSame([], File::glob("{$this->dir}/cache/*.json"));
        $this->assertFileDoesNotExist("{$this->dir}/out/fraud-eval-latest.json");
    }

    public function test_every_run_is_kept_in_the_history(): void
    {
        $this->useFake(FakeClaudeClient::verdict(10, 'clear'));
        $this->cases();

        Carbon::setTestNow('2026-10-11 10:00');
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();
        Carbon::setTestNow('2026-10-12 10:00');
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();

        // Same prompt and day but different cases: a separate run file, not an overwrite.
        $this->writeCases('FE-01,Farragut Grind,cafe,6.50,08:40,120.00,"x",1,2,12,13.87,normal,coffee,dev');
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();

        $latest = json_decode(file_get_contents("{$this->dir}/out/fraud-eval-latest.json"), true);
        $this->assertCount(3, $latest['history']);
        $this->assertCount(3, File::glob("{$this->dir}/out/runs/*.json"));
    }

    public function test_the_configured_headline_run_stays_the_headline_and_notes_are_saved(): void
    {
        $this->cases();

        Carbon::setTestNow('2026-10-11 10:00');
        $this->useFake(FakeClaudeClient::verdict(85, 'hold'));
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();
        config(['fraud.eval_headline' => [
            'run' => basename(File::glob("{$this->dir}/out/runs/*.json")[0], '.json'),
            'split' => 'holdout',
            'note' => 'Every disagreement is a false alarm.',
        ]]);

        // A later run (a different cache, as if the prompt changed) does not replace the headline.
        Carbon::setTestNow('2026-10-12 10:00');
        $this->useFake(FakeClaudeClient::verdict(10, 'clear'));
        $this->artisan('fraud:eval', ['--note' => 'Prompt changed after holdout was seen.', '--cache' => "{$this->dir}/cache2"] + $this->evalCommand())->assertSuccessful();

        $latest = json_decode(file_get_contents("{$this->dir}/out/fraud-eval-latest.json"), true);
        $this->assertSame('Prompt changed after holdout was seen.', $latest['note']);
        $this->assertSame('2026-10-11', substr($latest['headline']['run_at'], 0, 10));
        $headline = 'Caught 1 of 1 suspicious cases; 1 of 2 holds were suspicious.';
        $this->assertSame($headline, $latest['headline']['text']);
        $this->assertStringStartsWith('Measured on 2 unseen cases, 1 of them labeled suspicious by a person, with the current prompt.', $latest['headline']['sample']);
        $this->assertStringContainsString('overall agreement 50% (1 of 2, ', $latest['headline']['sample']);

        $md = file_get_contents("{$this->dir}/out/fraud-eval-results.md");
        $this->assertStringContainsString("**Headline: {$headline}**", $md);
        $this->assertStringContainsString('Every disagreement is a false alarm.', $md);
        $this->assertStringContainsString('Prompt changed after holdout was seen.', $md);

        config(['fraud.eval_latest_path' => "{$this->dir}/out/fraud-eval-latest.json"]);
        $this->get('/eval')
            ->assertSee('Caught 1 of 1 suspicious cases; 1 of 2 holds were suspicious.')
            ->assertSee('Every disagreement is a false alarm.')
            ->assertSee('Measured on 2 unseen cases, 1 of them labeled suspicious')
            ->assertSee('Misses and false alarms by pattern')
            ->assertDontSee('suspicious cases missed')
            ->assertSee('Note on this run:')
            ->assertSee('Earlier runs');
    }

    public function test_holdout_2_is_reported_as_its_own_group(): void
    {
        $this->useFake(FakeClaudeClient::verdict(85, 'hold'));
        $this->writeCases(
            'FE-01,Farragut Grind,cafe,6.50,08:40,120.00,"x",1,2,12,13.87,normal,coffee,dev',
            'FE-02,Triangle Stems,retail,150.00,23:40,150.00,"x",0,1,12,13.87,suspicious,drains,holdout',
            'FE-31,K Street Cuts,salon,200.00,01:30,260.00,"x",0,0,12,13.87,suspicious,never there,holdout_2',
            'FE-32,Farragut Grind,cafe,8.40,07:50,57.25,"x",2,5,12,13.87,normal,coffee,holdout_2',
        );

        $this->artisan('fraud:eval', $this->evalCommand())
            ->expectsOutputToContain('Holdout: 100% agreement (1/1)')
            ->expectsOutputToContain('Holdout 2: 50% agreement (1/2)')
            ->assertSuccessful();

        $latest = json_decode(file_get_contents("{$this->dir}/out/fraud-eval-latest.json"), true);
        $this->assertSame(2, $latest['summary']['holdout_2']['cases']);
        $this->assertSame(50.0, $latest['history'][0]['holdout_2_pct']);
        $this->assertStringContainsString('**Holdout 2**', file_get_contents("{$this->dir}/out/fraud-eval-results.md"));

        config(['fraud.eval_latest_path' => "{$this->dir}/out/fraud-eval-latest.json"]);
        $this->get('/eval')->assertSee('Holdout 2 agreement')->assertSee('Holdout 2 cases');
    }

    public function test_catch_rate_and_hold_precision_are_reported_with_wilson_intervals(): void
    {
        // Labels: 3 suspicious, 2 normal. AI: holds 2 suspicious and 1 normal, clears the rest.
        $results = array_map(fn ($pair) => ['my_label' => $pair[0], 'ai_label' => $pair[1], 'category' => 'cafe', 'tags' => []], [
            ['suspicious', 'suspicious'], ['suspicious', 'suspicious'], ['suspicious', 'normal'],
            ['normal', 'suspicious'], ['normal', 'normal'],
        ]);

        $s = FraudEvalRunner::summarize($results);

        $this->assertSame(['hits' => 2, 'of' => 3, 'pct' => 66.7, 'ci95' => [20.8, 93.9]], $s['catch']);
        $this->assertSame(['hits' => 2, 'of' => 3, 'pct' => 66.7, 'ci95' => [20.8, 93.9]], $s['hold_precision']);
        $this->assertSame([23.1, 88.2], $s['agreement_ci95']);
        $this->assertSame(['cases' => 5, 'suspicious' => 3, 'missed' => 1, 'false_alarms' => 1], $s['patterns']['categories']['cafe']);
    }

    public function test_pattern_tags_come_from_the_facts_only(): void
    {
        $runner = app(FraudEvalRunner::class);
        $case = fn (string $row) => array_combine(explode(',', self::HEADER), str_getcsv($row, escape: ''));

        // $60 round, 4x the $15 median, 96% of balance, new merchant, 23:00, salon range is $25-$90.
        $busy = $case('FE-99,K Street Cuts,salon,60.00,23:00,62.50,"x",0,0,12,15.00,normal,x,dev');
        $this->assertSame(
            ['round_amount', 'triple_median', 'most_of_balance', 'new_merchant', 'outside_hours'],
            FraudEvalPatterns::tags($busy, $runner->signalsFor($busy)),
        );

        // Same facts with the opposite label give the same tags.
        $relabeled = ['my_label' => 'suspicious', 'notes' => 'other'] + $busy;
        $this->assertSame(FraudEvalPatterns::tags($busy, $runner->signalsFor($busy)), FraudEvalPatterns::tags($relabeled, $runner->signalsFor($relabeled)));

        // A $25 coffee from a 6-visits-a-week regular: frequent visitor, above the cafe range.
        $regular = $case('FE-98,Farragut Grind,cafe,25.00,12:00,140.00,"x",6,14,30,6.20,normal,x,dev');
        $this->assertSame(['triple_median', 'frequent_visitor', 'above_category'], FraudEvalPatterns::tags($regular, $runner->signalsFor($regular)));

        // No past redemptions: no median, so never "3x the median".
        $first = $case('FE-97,Triangle Stems,retail,12.00,12:00,100.00,"x",0,0,0,,normal,x,dev');
        $this->assertSame(['new_merchant'], FraudEvalPatterns::tags($first, $runner->signalsFor($first)));
    }

    public function test_eval_page_shows_the_lock_for_the_headline_split(): void
    {
        $this->useFake(FakeClaudeClient::verdict(85, 'hold'));
        $cases = app(FraudEvalRunner::class)->loadCases($this->writeCases(
            'FE-41,Farragut Grind,cafe,5.40,08:05,92.30,"x",2,6,18,7.10,normal,small,holdout_3',
            'FE-42,K Street Cuts,salon,180.00,21:40,184.50,"x",0,0,9,16.20,suspicious,drains,holdout_3',
        ));
        file_put_contents("{$this->dir}/lock.json", json_encode(FraudEvalLock::build($cases, str_repeat('ab', 20), '2026-10-14T12:00:00-04:00')));
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();
        config([
            'fraud.eval_latest_path' => "{$this->dir}/out/fraud-eval-latest.json",
            'fraud.eval_headline' => ['run' => basename(File::glob("{$this->dir}/out/runs/*.json")[0], '.json'), 'split' => 'holdout_3', 'note' => null],
        ]);
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();

        $this->get('/eval')
            ->assertSee('Caught 1 of 1 suspicious cases; 1 of 2 holds were suspicious.')
            ->assertSee('Locked before the AI saw them:')
            ->assertSee('/commit/'.str_repeat('ab', 20), false)
            ->assertSee('Holdout 3 agreement');
        $this->assertStringContainsString('labeled by hand in commit `ababab', file_get_contents("{$this->dir}/out/fraud-eval-results.md"));
    }

    public function test_the_real_cases_file_is_fully_labeled_and_valid(): void
    {
        $cases = app(FraudEvalRunner::class)->loadCases(base_path('data/fraud_eval_cases.csv'));

        $this->assertCount(20, array_filter($cases, fn ($c) => $c['split'] === 'dev'));
        $this->assertCount(10, array_filter($cases, fn ($c) => $c['split'] === 'holdout'));
        $this->assertCount(10, array_filter($cases, fn ($c) => $c['split'] === 'holdout_2'));
        $this->assertCount(50, array_filter($cases, fn ($c) => $c['split'] === 'holdout_3'));
    }

    public function test_the_real_holdout_3_cases_still_match_their_lock(): void
    {
        $cases = app(FraudEvalRunner::class)->loadCases(base_path('data/fraud_eval_cases.csv'));
        $lock = json_decode(file_get_contents(base_path('data/fraud_eval_lock.json')), true);

        $this->assertSame([], FraudEvalLock::problems($cases, $lock), 'A locked holdout_3 case was changed after labeling.');
        $this->assertSame(50, $lock['case_count']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $lock['labeling_commit']);
        $this->assertSame(
            FraudEvalLock::build($cases, $lock['labeling_commit'], $lock['locked_at'])['combined_sha256'],
            $lock['combined_sha256'],
        );
    }

    public function test_fraud_eval_refuses_to_run_when_a_locked_case_changed(): void
    {
        $fake = $this->useFake(FakeClaudeClient::verdict(10, 'clear'));
        $rows = [
            'FE-41,Farragut Grind,cafe,5.40,08:05,92.30,"x",2,6,18,7.10,normal,small,holdout_3',
            'FE-42,K Street Cuts,salon,180.00,21:40,184.50,"x",0,0,9,16.20,suspicious,drains,holdout_3',
        ];
        $cases = app(FraudEvalRunner::class)->loadCases($this->writeCases(...$rows));
        file_put_contents("{$this->dir}/lock.json", json_encode(FraudEvalLock::build($cases, str_repeat('a', 40), '2026-10-14T12:00:00-04:00')));

        // Unchanged: runs.
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();

        // A relabel, a new case and a removed case are each refused before any AI call.
        $fake->calls = [];
        $this->writeCases(
            'FE-41,Farragut Grind,cafe,5.40,08:05,92.30,"x",2,6,18,7.10,suspicious,small,holdout_3',
            'FE-43,Press Room Deli,restaurant,12.75,12:20,66.00,"x",1,3,14,11.40,normal,lunch,holdout_3',
        );
        $this->artisan('fraud:eval', ['--cache' => "{$this->dir}/cache2"] + $this->evalCommand())
            ->expectsOutputToContain('FE-41: changed since it was locked')
            ->expectsOutputToContain('FE-42: locked case is missing')
            ->expectsOutputToContain('FE-43: not in the lock')
            ->assertFailed();

        $this->assertSame([], $fake->calls);
    }

    public function test_an_existing_lock_is_never_replaced_without_force(): void
    {
        file_put_contents("{$this->dir}/lock.json", '{"rows":{}}');

        $this->artisan('fraud:lock')->expectsOutputToContain('already locked')->assertFailed();
        $this->assertSame('{"rows":{}}', file_get_contents("{$this->dir}/lock.json"));
    }

    public function test_loading_a_missing_file_fails_clearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(FraudEvalRunner::class)->loadCases("{$this->dir}/nope.csv");
    }

    public function test_eval_page_shows_an_empty_state_without_results(): void
    {
        config(['fraud.eval_latest_path' => "{$this->dir}/missing.json"]);

        $this->get('/eval')
            ->assertOk()
            ->assertSee('No eval results yet');
    }

    public function test_eval_page_shows_the_latest_results(): void
    {
        $this->useFake(FakeClaudeClient::verdict(85, 'hold', 'Round full-balance redemption late at night.'));
        $this->cases();
        $this->artisan('fraud:eval', $this->evalCommand())->assertSuccessful();
        config(['fraud.eval_latest_path' => "{$this->dir}/out/fraud-eval-latest.json"]);

        $this->get('/eval')
            ->assertOk()
            ->assertSee('Holdout agreement')
            ->assertSee('50%')
            ->assertSee('claude-opus-5-5')
            ->assertSee('Every disagreement (2)')
            ->assertSee('coffee')
            ->assertSee('Round full-balance redemption late at night.');
    }
}
