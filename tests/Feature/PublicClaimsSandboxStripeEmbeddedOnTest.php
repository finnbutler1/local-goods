<?php

namespace Tests\Feature;

/**
 * The public claims checks once more with the sandbox's embedded card form
 * on (ADR 0045 addendum): the sandbox account's publishable key is set too,
 * so Buy credits says "pay by card right here" instead of "Stripe's page".
 */
class PublicClaimsSandboxStripeEmbeddedOnTest extends PublicClaimsSandboxStripeOnTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sandbox.stripe.publishable' => 'pk_test_sandbox_account_example_not_a_real_key']);
    }
}
