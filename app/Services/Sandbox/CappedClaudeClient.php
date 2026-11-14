<?php

namespace App\Services\Sandbox;

use App\Services\Ai\ClaudeClient;
use RuntimeException;

/**
 * The Claude client inside a sandbox (ADR 0045). It passes the prompt, the
 * input and the schema through untouched; it only refuses once the sandbox
 * or the day has used its calls. The refusal is an exception, so Redemption
 * Fraud Detection takes its existing fail-closed path and holds the payment
 * for a person to review.
 */
class CappedClaudeClient extends ClaudeClient
{
    public const LIMIT_MESSAGE = 'The sandbox has used its AI checks.';

    public function __construct(private ClaudeClient $inner, private SandboxAiBudget $budget) {}

    public function model(): string
    {
        return $this->inner->model();
    }

    public function json(string $system, string $userContent, array $schema): string
    {
        if (! $this->budget->take()) {
            throw new RuntimeException(self::LIMIT_MESSAGE);
        }

        return $this->inner->json($system, $userContent, $schema);
    }
}
