<?php

namespace Tests\Feature;

use App\Services\Ai\ClaudeClient;
use App\Services\Fraud\RedemptionFraudDetector;
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
}
