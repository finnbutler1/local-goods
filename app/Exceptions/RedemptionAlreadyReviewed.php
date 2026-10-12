<?php

namespace App\Exceptions;

use App\Models\Redemption;
use RuntimeException;

/** Thrown when someone tries to approve or reject a redemption that is not under review. */
class RedemptionAlreadyReviewed extends RuntimeException
{
    public function __construct(public readonly Redemption $redemption)
    {
        parent::__construct("Redemption {$redemption->id} is {$redemption->status}, not under review.");
    }
}
