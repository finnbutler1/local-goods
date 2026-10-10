<?php

namespace App\Services\Ai;

use Anthropic\Client;
use RuntimeException;

/**
 * Thin wrapper around the Anthropic SDK so the rest of the app (and the tests)
 * only depend on one small method. Tests swap this class for a fake.
 */
class ClaudeClient
{
    public function model(): string
    {
        return (string) config('services.anthropic.model');
    }

    /**
     * Send one message and return the raw JSON text Claude produced.
     *
     * @param  array<string, mixed>  $schema  JSON schema the reply must follow
     *
     * @throws RuntimeException when the key is missing, the model refuses, or no text comes back
     */
    public function json(string $system, string $userContent, array $schema): string
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        $client = new Client(apiKey: $key);

        $message = $client->messages->create(
            model: $this->model(),
            maxTokens: 4000,
            system: $system,
            messages: [['role' => 'user', 'content' => $userContent]],
            outputConfig: [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            requestOptions: ['timeout' => (float) config('services.anthropic.timeout'), 'maxRetries' => 1],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The model declined to score this redemption.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new RuntimeException('The model returned no text.');
    }
}
