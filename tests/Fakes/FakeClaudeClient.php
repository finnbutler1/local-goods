<?php

namespace Tests\Fakes;

use App\Services\Ai\ClaudeClient;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stands in for the real Claude API in tests. Give it a JSON string to
 * return, an exception to throw, or a function that answers each message
 * (given the user content). Records what it was sent.
 */
class FakeClaudeClient extends ClaudeClient
{
    /** @var list<array{system: string, user: string, transaction_level: int}> */
    public array $calls = [];

    public function __construct(private string|Throwable|Closure $reply) {}

    public static function verdict(int $score, string $decision, string $explanation = 'Looks like a normal purchase.'): self
    {
        return new self(json_encode(['risk_score' => $score, 'decision' => $decision, 'explanation' => $explanation]));
    }

    public function json(string $system, string $userContent, array $schema): string
    {
        $this->calls[] = ['system' => $system, 'user' => $userContent, 'transaction_level' => DB::transactionLevel()];

        $reply = $this->reply instanceof Closure ? ($this->reply)($userContent) : $this->reply;

        if ($reply instanceof Throwable) {
            throw $reply;
        }

        return $reply;
    }
}
