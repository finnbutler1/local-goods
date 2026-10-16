<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * A Stripe call failed. The message is safe to show and log: it never holds
 * a key. `$definite` is false when we can't tell whether Stripe acted (a
 * timeout), so a retry must reuse the same idempotency key.
 */
class StripeGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $definite = true, public readonly ?string $stripeCode = null)
    {
        parent::__construct($message);
    }
}
