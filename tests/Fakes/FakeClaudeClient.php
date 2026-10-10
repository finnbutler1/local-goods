<?php

namespace Tests\Fakes;

use App\Services\Ai\ClaudeClient;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stands in for the real Claude API in tests. Give it a JSON string to
 * return, or an exception to throw. Records what it was sent.
 */
class FakeClaudeClient extends ClaudeClient
{
    /** @var list<array{system: string, user: string, transaction_level: int}> */
    public array $calls = [];

    public function __construct(private string|Throwable $reply) {}

    public static function verdict(int $score, string $decision, string $explanation = 'Looks like a normal purchase.'): self
    {
        return new self(json_encode(['risk_score' => $score, 'decision' => $decision, 'explanation' => $explanation]));
    }

    public function json(string $system, string $userContent, array $schema): string
    {
        $this->calls[] = ['system' => $system, 'user' => $userContent, 'transaction_level' => DB::transactionLevel()];

        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }

        return $this->reply;
    }
}
