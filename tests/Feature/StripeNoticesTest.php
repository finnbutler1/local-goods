<?php

namespace Tests\Feature;

use App\Services\Payments\NoticeSafeStripeGateway;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeNotices;
use App\Services\Payments\StripeSdkGateway;
use ErrorException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;
use Tests\TestCase;

/**
 * The real stripe-php SDK, with its HTTP client swapped for a stub that
 * answers like Stripe does when it adds a "Stripe-Notice" header. Proves
 * the notice can't crash a request, even when the library ignores
 * STRIPE_SUPPRESS_NOTICES because it detects an AI coding agent.
 */
class StripeNoticesTest extends TestCase
{
    private const NOTICE = 'We recommend building your integration using Accounts v2 (https://docs.stripe.com/connect/accounts-v2).';

    private string|false $claudeCode;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_SECRETVALUE', 'services.stripe.webhook_secret' => 'whsec_x']);

        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return [
                    json_encode(['id' => 'acct_test_stub', 'object' => 'account', 'details_submitted' => false, 'payouts_enabled' => false, 'capabilities' => ['transfers' => 'inactive']]),
                    200,
                    new CaseInsensitiveArray(['Request-Id' => 'req_stub', 'Stripe-Notice' => 'We recommend building your integration using Accounts v2 (https://docs.stripe.com/connect/accounts-v2).']),
                ];
            }
        });

        // As in a terminal started by an AI agent: stripe-php then ignores STRIPE_SUPPRESS_NOTICES.
        $this->claudeCode = getenv('CLAUDECODE');
        putenv('CLAUDECODE=1');
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        $this->claudeCode === false ? putenv('CLAUDECODE') : putenv('CLAUDECODE='.$this->claudeCode);
        parent::tearDown();
    }

    public function test_without_the_guard_the_notice_crashes_like_on_localhost(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Accounts v2');

        (new StripeSdkGateway)->createExpressAccount(['country' => 'US'], 'lg-test-raw');
    }

    public function test_the_bound_gateway_returns_the_account_and_logs_the_notice_safely(): void
    {
        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->message, $message->context]);
        });

        $gateway = app(StripeGateway::class);
        $this->assertInstanceOf(NoticeSafeStripeGateway::class, $gateway);

        $account = $gateway->createExpressAccount(['country' => 'US'], 'lg-test-guarded');

        $this->assertSame('acct_test_stub', $account['id']);
        $this->assertStringContainsString('Accounts v2', implode(' ', $logged));
        $this->assertStringNotContainsString('SECRETVALUE', implode(' ', $logged));
    }

    public function test_real_php_errors_are_still_raised(): void
    {
        $this->expectException(ErrorException::class);

        // A genuine PHP warning (not a Stripe notice) still reaches Laravel.
        $empty = [];
        StripeNotices::quietly('test', fn () => $empty['missing']);
    }

    public function test_notices_are_redacted_before_logging(): void
    {
        $this->assertSame('key [redacted] and [redacted]', StripeNotices::safe("key sk_test_abc123 and whsec_zzz\nsecond line"));
    }
}
