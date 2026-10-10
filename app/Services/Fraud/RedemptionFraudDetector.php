<?php

namespace App\Services\Fraud;

use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redemption Fraud Detection: asks Claude to score a redemption from its
 * signals. Fails closed: any error or malformed reply means "hold".
 */
class RedemptionFraudDetector
{
    public const FALLBACK_EXPLANATION = 'Automatic hold: the AI check could not be completed, so a person will review this redemption.';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'risk_score' => ['type' => 'integer', 'description' => '0 (no concern) to 100 (almost certainly abuse)'],
            'decision' => ['type' => 'string', 'enum' => ['clear', 'hold']],
            'explanation' => ['type' => 'string', 'description' => 'One or two plain-English sentences'],
        ],
        'required' => ['risk_score', 'decision', 'explanation'],
        'additionalProperties' => false,
    ];

    public function __construct(private ClaudeClient $claude) {}

    /**
     * @param  array<string, mixed>  $signals
     */
    public function score(array $signals): FraudVerdict
    {
        try {
            $raw = $this->claude->json(
                system: file_get_contents(resource_path('prompts/redemption-fraud-detection.md')),
                userContent: json_encode($signals, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                schema: self::SCHEMA,
            );

            return $this->parse($raw) ?? $this->fallback('invalid reply');
        } catch (Throwable $e) {
            // Log the error class and message only, never request headers or the API key.
            return $this->fallback($e::class.': '.$e->getMessage());
        }
    }

    private function parse(string $raw): ?FraudVerdict
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        $score = $data['risk_score'] ?? null;
        $decision = $data['decision'] ?? null;
        $explanation = $data['explanation'] ?? null;

        if (! is_int($score) || $score < 0 || $score > 100
            || ! in_array($decision, [FraudVerdict::CLEAR, FraudVerdict::HOLD], true)
            || ! is_string($explanation) || trim($explanation) === '') {
            return null;
        }

        // Safety rule: a high score is never cleared, whatever the decision field says.
        if ($decision === FraudVerdict::CLEAR && $score >= config('fraud.hold_if_score_at_least')) {
            $decision = FraudVerdict::HOLD;
        }

        return new FraudVerdict($score, $decision, trim($explanation), $this->claude->model());
    }

    private function fallback(string $reason): FraudVerdict
    {
        Log::warning('Redemption Fraud Detection fell back to hold', ['reason' => $reason]);

        return new FraudVerdict(100, FraudVerdict::HOLD, self::FALLBACK_EXPLANATION, 'fallback');
    }
}
