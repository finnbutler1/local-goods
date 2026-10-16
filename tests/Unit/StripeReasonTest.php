<?php

namespace Tests\Unit;

use App\Services\Payments\StripeSdkGateway;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;

/**
 * Stripe's own reason is shown to people only when safe: the first
 * sentence and any "- field: ..." lines, no example code, no keys, and
 * nothing at all for key or permission errors.
 */
class StripeReasonTest extends TestCase
{
    /** Stripe's actual reply to our v1 Express request on 2026-10-16 (shortened). */
    private const REFUSAL = "Stripe no longer recommends Accounts v1 for new Connect integrations. Create connected accounts with POST /v2/core/accounts instead.\n\n"
        ."There are required fields missing (set once at account creation):\n"
        ."- contact_email: an email address for the account, required whenever a \"recipient\" configuration is applied\n\n"
        ."curl --request POST https://api.stripe.com/v2/core/accounts \\\n  --header \"Authorization: Bearer \$STRIPE_SECRET_KEY\" \\\n  --json '{\"display_name\": \"Farragut Grind\"}'";

    private function refusal(string $message, string $class = InvalidRequestException::class): \Stripe\Exception\ApiErrorException
    {
        return $class::factory($message, 400, null, ['error' => ['type' => 'invalid_request_error', 'message' => $message]]);
    }

    public function test_it_keeps_the_first_sentence_and_the_missing_field_lines_only(): void
    {
        $reason = StripeSdkGateway::reason($this->refusal(self::REFUSAL));

        $this->assertStringStartsWith('Stripe no longer recommends Accounts v1 for new Connect integrations.', $reason);
        $this->assertStringContainsString('- contact_email: an email address for the account', $reason);
        $this->assertStringNotContainsString('curl', $reason);
        $this->assertStringNotContainsString('Authorization', $reason);
        $this->assertLessThanOrEqual(300, mb_strlen($reason));
    }

    public function test_anything_that_looks_like_a_key_is_removed(): void
    {
        $this->assertSame('Bad key sk_test_ nope: [redacted] here.', StripeSdkGateway::reason($this->refusal('Bad key sk_test_ nope: sk_test_abc123 here.')));
    }

    public function test_key_errors_show_no_reason_at_all(): void
    {
        $this->assertNull(StripeSdkGateway::reason($this->refusal('Invalid API Key provided: sk_test_****abcd', AuthenticationException::class)));
    }

    public function test_an_empty_message_gives_no_reason(): void
    {
        $this->assertNull(StripeSdkGateway::reason($this->refusal('')));
    }
}
