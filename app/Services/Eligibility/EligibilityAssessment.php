<?php

namespace App\Services\Eligibility;

/**
 * One answer from Merchant Eligibility Matching. assessedBy is the model
 * name, or "fallback" when the AI check failed and a person must decide.
 */
final readonly class EligibilityAssessment
{
    public const CLASSIFICATIONS = ['independent', 'local_chain', 'franchise', 'national_chain', 'outside_control', 'undetermined'];

    public const FALLBACK = 'fallback';

    public function __construct(
        public bool $eligible,
        public string $classification,
        public float $confidence,
        public string $reasoning,
        public bool $needsHumanReview,
        public string $assessedBy,
    ) {}

    public function isFallback(): bool
    {
        return $this->assessedBy === self::FALLBACK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'classification' => $this->classification,
            'confidence' => $this->confidence,
            'reasoning' => $this->reasoning,
            'needs_human_review' => $this->needsHumanReview,
            'assessed_by' => $this->assessedBy,
        ];
    }

    /** @param  array<string, mixed>  $data  from toArray() */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) $data['eligible'],
            (string) $data['classification'],
            (float) $data['confidence'],
            (string) $data['reasoning'],
            (bool) $data['needs_human_review'],
            (string) $data['assessed_by'],
        );
    }
}
