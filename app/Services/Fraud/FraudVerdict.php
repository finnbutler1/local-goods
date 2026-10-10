<?php

namespace App\Services\Fraud;

final readonly class FraudVerdict
{
    public const CLEAR = 'clear';
    public const HOLD = 'hold';

    public function __construct(
        public int $riskScore,
        public string $decision,
        public string $explanation,
        public string $scoredBy,
    ) {}

    public function isClear(): bool
    {
        return $this->decision === self::CLEAR;
    }
}
