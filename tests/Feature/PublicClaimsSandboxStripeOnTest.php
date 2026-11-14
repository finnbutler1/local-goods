<?php

namespace Tests\Feature;

use App\Services\Sandbox\SandboxCheckoutClient;
use Tests\Fakes\FakeSandboxCheckoutClient;

/**
 * The public claims checks once more with Stripe Checkout switched on in
 * sandboxes (ADR 0045 addendum): every sandbox page, as every role, says the
 * separate-account sentence and nothing the wording rules forbid.
 */
class PublicClaimsSandboxStripeOnTest extends PublicClaimsSandboxOnTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => 'sk_test_fake_sandbox_account_not_a_real_key']);
        $this->app->instance(SandboxCheckoutClient::class, new FakeSandboxCheckoutClient);
    }

    protected function expectedBanner(): string
    {
        return 'Sandbox: your own copy. Payments use a separate Stripe test account: no real money, and nothing reaches the live demo. Deleted after 24 hours.';
    }
}
