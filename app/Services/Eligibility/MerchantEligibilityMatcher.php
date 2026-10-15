<?php

namespace App\Services\Eligibility;

use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Merchant Eligibility Matching: asks Claude whether one business is
 * independent under the rule in the prompt, from public license facts only.
 * Claude has no web access here, so it judges from its own knowledge.
 * Fails closed: any error or invalid reply means "undetermined, a person
 * decides", never a guess.
 */
class MerchantEligibilityMatcher
{
    /** The only candidate fields the AI ever sees. Hand labels and notes live in another file and are never read here. */
    public const INPUT_FIELDS = ['name', 'address', 'category', 'business_activity'];

    public const FALLBACK_REASONING = 'The AI check could not be completed, so this business is undetermined and a person must decide.';

    /** Shown next to every AI reasoning: the AI judged from memory, not a live search. */
    public const KNOWLEDGE_NOTICE = 'Judged from the AI\'s own training knowledge and the public license facts shown. It did not search the web, so it may not know about recent sales, closures or new owners.';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'eligible' => ['type' => 'boolean'],
            'classification' => ['type' => 'string', 'enum' => EligibilityAssessment::CLASSIFICATIONS],
            'confidence' => ['type' => 'number', 'description' => '0 to 1'],
            'reasoning' => ['type' => 'string', 'description' => 'Two to four plain-English sentences'],
            'needs_human_review' => ['type' => 'boolean'],
        ],
        'required' => ['eligible', 'classification', 'confidence', 'reasoning', 'needs_human_review'],
        'additionalProperties' => false,
    ];

    public function __construct(private ClaudeClient $claude) {}

    public static function promptPath(): string
    {
        return resource_path('prompts/merchant-eligibility.md');
    }

    /** Short fingerprint of the prompt file, so every result names the exact prompt it used. */
    public static function promptVersion(): string
    {
        return substr(hash_file('sha256', self::promptPath()), 0, 8);
    }

    /**
     * The JSON the AI receives for a candidate: the allowed fields only.
     *
     * @param  array<string, string>  $candidate  a row of the candidates file, plus business_activity
     */
    public static function input(array $candidate): string
    {
        $facts = [];
        foreach (self::INPUT_FIELDS as $field) {
            $facts[$field] = (string) ($candidate[$field] ?? '');
        }

        return json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string>  $candidate */
    public function assess(array $candidate): EligibilityAssessment
    {
        try {
            $raw = $this->claude->json(
                system: file_get_contents(self::promptPath()),
                userContent: self::input($candidate),
                schema: self::SCHEMA,
            );

            return $this->parse($raw) ?? $this->fallback('invalid reply');
        } catch (Throwable $e) {
            // Log the error class and message only, never request headers or the API key.
            return $this->fallback($e::class.': '.$e->getMessage());
        }
    }

    private function parse(string $raw): ?EligibilityAssessment
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        $eligible = $data['eligible'] ?? null;
        $classification = $data['classification'] ?? null;
        $confidence = $data['confidence'] ?? null;
        $reasoning = $data['reasoning'] ?? null;
        $review = $data['needs_human_review'] ?? null;

        if (! is_bool($eligible) || ! is_bool($review)
            || ! in_array($classification, EligibilityAssessment::CLASSIFICATIONS, true)
            || ! (is_int($confidence) || is_float($confidence)) || $confidence < 0 || $confidence > 1
            || ! is_string($reasoning) || trim($reasoning) === ''
            // Contradictions are invalid, not something to resolve by picking one side.
            || $eligible !== ($classification === 'independent')) {
            return null;
        }

        // Safety rule: undetermined or low confidence always goes to a person, whatever the AI said.
        if ($classification === 'undetermined' || $confidence < config('eligibility.review_if_confidence_below')) {
            $review = true;
        }

        return new EligibilityAssessment($eligible, $classification, (float) $confidence, trim($reasoning), $review, $this->claude->model());
    }

    private function fallback(string $reason): EligibilityAssessment
    {
        Log::warning('Merchant Eligibility Matching fell back to human review', ['reason' => $reason]);

        return new EligibilityAssessment(false, 'undetermined', 0.0, self::FALLBACK_REASONING, true, EligibilityAssessment::FALLBACK);
    }
}
