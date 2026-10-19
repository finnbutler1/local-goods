<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use App\Services\Fraud\RedemptionFraudDetector;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class RedemptionFraudDetectorTest extends TestCase
{
    private function detectorReturning(FakeClaudeClient $fake): RedemptionFraudDetector
    {
        $this->app->instance(ClaudeClient::class, $fake);

        return $this->app->make(RedemptionFraudDetector::class);
    }

    public function test_a_valid_clear_reply_clears(): void
    {
        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(8, 'clear'))->score(['amount_cents' => 650]);

        $this->assertTrue($verdict->isClear());
        $this->assertSame(8, $verdict->riskScore);
        $this->assertSame('claude-opus-5-5', $verdict->scoredBy);
    }

    public function test_an_api_failure_holds(): void
    {
        $verdict = $this->detectorReturning(new FakeClaudeClient(new RuntimeException('Connection timed out')))
            ->score(['amount_cents' => 650]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame(100, $verdict->riskScore);
        $this->assertSame('fallback', $verdict->scoredBy);
        $this->assertSame(RedemptionFraudDetector::FALLBACK_EXPLANATION, $verdict->explanation);
    }

    public function test_invalid_json_holds(): void
    {
        $verdict = $this->detectorReturning(new FakeClaudeClient('Sure! This looks fine.'))->score([]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame('fallback', $verdict->scoredBy);
    }

    public function test_reply_with_out_of_range_score_holds(): void
    {
        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(140, 'clear'))->score([]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame('fallback', $verdict->scoredBy);
    }

    public function test_reply_with_unknown_decision_holds(): void
    {
        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(10, 'approve'))->score([]);

        $this->assertFalse($verdict->isClear());
    }

    public function test_a_clear_with_a_high_score_is_held_anyway(): void
    {
        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(75, 'clear'))->score([]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame(75, $verdict->riskScore);
        $this->assertSame('claude-opus-5-5', $verdict->scoredBy);
    }

    public function test_only_signals_are_sent_with_the_prompt_file(): void
    {
        $fake = FakeClaudeClient::verdict(5, 'clear');
        $this->detectorReturning($fake)->score(['amount_cents' => 650, 'merchant_category' => 'cafe']);

        $this->assertSame(file_get_contents(resource_path('prompts/redemption-fraud-detection.md')), $fake->calls[0]['system']);
        $this->assertSame(['amount_cents' => 650, 'merchant_category' => 'cafe'], json_decode($fake->calls[0]['user'], true));
    }

    public function test_anthropic_model_env_setting_changes_the_model(): void
    {
        config(['services.anthropic.model' => 'claude-haiku-5-5']);

        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(5, 'clear'))->score([]);

        $this->assertSame('claude-haiku-5-5', $verdict->scoredBy);
    }

    public function test_missing_api_key_holds(): void
    {
        config(['services.anthropic.key' => null]);

        $verdict = $this->app->make(RedemptionFraudDetector::class)->score(['amount_cents' => 650]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame('fallback', $verdict->scoredBy);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidReplies(): array
    {
        $valid = ['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks normal.'];

        return [
            'negative score' => [json_encode(['risk_score' => -1] + $valid)],
            'score as text' => [json_encode(['risk_score' => '10'] + $valid)],
            'score with decimals' => [json_encode(['risk_score' => 10.5] + $valid)],
            'blank explanation' => [json_encode(['explanation' => "  \n "] + $valid)],
            'explanation not text' => [json_encode(['explanation' => ['Looks normal.']] + $valid)],
            'missing decision' => [json_encode(array_diff_key($valid, ['decision' => 1]))],
            'decision in capitals' => [json_encode(['decision' => 'CLEAR'] + $valid)],
            'a list, not an object' => ['[]'],
            'a bare word' => ['"clear"'],
            'empty reply' => [''],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_every_invalid_reply_holds(string $reply): void
    {
        $verdict = $this->detectorReturning(new FakeClaudeClient($reply))->score([]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame(100, $verdict->riskScore);
        $this->assertSame('fallback', $verdict->scoredBy);
    }

    public function test_the_hold_threshold_is_exactly_seventy(): void
    {
        $this->assertSame(70, config('fraud.hold_if_score_at_least'));

        $this->assertTrue($this->detectorReturning(FakeClaudeClient::verdict(69, 'clear'))->score([])->isClear());
        $this->assertFalse($this->detectorReturning(FakeClaudeClient::verdict(70, 'clear'))->score([])->isClear());
    }

    public function test_the_ends_of_the_score_range_are_valid_answers(): void
    {
        $low = $this->detectorReturning(FakeClaudeClient::verdict(0, 'clear'))->score([]);
        $high = $this->detectorReturning(FakeClaudeClient::verdict(100, 'hold'))->score([]);

        $this->assertTrue($low->isClear());
        $this->assertSame([0, 'claude-opus-5-5'], [$low->riskScore, $low->scoredBy]);
        $this->assertSame([100, 'claude-opus-5-5'], [$high->riskScore, $high->scoredBy]);
    }

    public function test_the_ais_own_hold_is_kept_even_with_a_low_score(): void
    {
        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(5, 'hold'))->score([]);

        $this->assertFalse($verdict->isClear());
        $this->assertSame(5, $verdict->riskScore);
        $this->assertSame('claude-opus-5-5', $verdict->scoredBy);
    }

    public function test_the_explanation_is_trimmed(): void
    {
        $verdict = $this->detectorReturning(FakeClaudeClient::verdict(8, 'clear', "  A normal coffee.\n"))->score([]);

        $this->assertSame('A normal coffee.', $verdict->explanation);
    }

    public function test_a_failure_is_logged_without_the_api_key(): void
    {
        Log::spy();
        config(['services.anthropic.key' => 'sk-ant-test-not-a-real-key']);

        $this->detectorReturning(new FakeClaudeClient(new RuntimeException('Connection timed out')))->score([]);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Redemption Fraud Detection fell back to hold'
            && ! str_contains(json_encode($context), 'sk-ant-')
            && str_contains($context['reason'], 'Connection timed out'));
    }
}
