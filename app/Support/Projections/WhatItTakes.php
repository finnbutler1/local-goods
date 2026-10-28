<?php

namespace App\Support\Projections;

/**
 * What scenario A (one BID, fully enrolled) needs for its monthly EBITDA to
 * reach 0 (ADR 0030): participating employees at the current fees, and the
 * employer fee at the assumed participation. Null means not reachable
 * within the search. A projection, not a result.
 */
final readonly class WhatItTakes
{
    public function __construct(
        public string $case,
        public ?int $employees,
        public ?int $employers,
        public float $employeesPerEmployer,
        public float $currentFee,
        public float $participation,
        public ?float $breakEvenFee,
    ) {}
}
