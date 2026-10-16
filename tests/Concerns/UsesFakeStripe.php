<?php

namespace Tests\Concerns;

use App\Services\Payments\NoticeSafeStripeGateway;
use App\Services\Payments\StripeGateway;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeStripeGateway;

/**
 * Stripe test mode for a test, with no network: test-looking keys in config
 * and FakeStripeGateway bound in place of the real SDK. Webhooks are signed
 * exactly the way Stripe signs them, so the real verification runs.
 */
trait UsesFakeStripe
{
    protected const WEBHOOK_SECRET = 'whsec_test_local_goods_fake';

    protected FakeStripeGateway $stripe;

    protected function fakeStripe(): FakeStripeGateway
    {
        config([
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $this->stripe = new FakeStripeGateway;
        // Wrapped like the real one (AppServiceProvider), so notices are tested too.
        $this->app->instance(StripeGateway::class, new NoticeSafeStripeGateway($this->stripe));

        return $this->stripe;
    }

    /** A Stripe-shaped event around $object. */
    protected function stripeEvent(string $type, array $object, ?string $id = null, ?string $account = null): array
    {
        return array_filter([
            'id' => $id ?? 'evt_test_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'livemode' => false,
            'account' => $account,
            'data' => ['object' => $object],
        ], fn ($v) => $v !== null);
    }

    protected function signedHeader(string $payload, string $secret = self::WEBHOOK_SECRET, ?int $at = null): string
    {
        $at ??= time();

        return 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, $secret);
    }

    protected function postWebhook(array $event, ?string $header = null, array $server = [], string $url = '/stripe/webhook'): TestResponse
    {
        $payload = json_encode($event);

        return $this->call('POST', $url, [], [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header ?? $this->signedHeader($payload),
        ], $payload);
    }
}
