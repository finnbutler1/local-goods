<?php

namespace Tests\Feature;

use App\Support\EmployerPlatformFee;

/**
 * Every public claims check again, with the employer platform fee switched
 * on (ADR 0042), as it is on the live site in Stripe test mode: the planned
 * fee preview is gone, and the only fee figures a judge sees are the
 * employer dashboard's own lines, each labelled "no real money".
 */
class PublicClaimsEmployerFeeOnTest extends PublicClaimsTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.employer_platform_fee_enabled' => true]);
        $this->assertTrue(EmployerPlatformFee::charges());
    }
}
