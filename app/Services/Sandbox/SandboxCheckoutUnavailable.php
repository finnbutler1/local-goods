<?php

namespace App\Services\Sandbox;

use RuntimeException;

/** This sandbox may not open another Stripe Checkout (its limit is used up): Buy credits falls back to simulated. */
class SandboxCheckoutUnavailable extends RuntimeException {}
