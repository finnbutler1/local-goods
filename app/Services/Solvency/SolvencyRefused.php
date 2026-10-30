<?php

namespace App\Services\Solvency;

use InvalidArgumentException;

/**
 * An operation refused by the solvency guard (ADR 0033): it would have left
 * less money in hand than has been promised. An InvalidArgumentException,
 * like every other money refusal, so the same error toast shows it and the
 * transaction it was thrown in writes nothing.
 */
class SolvencyRefused extends InvalidArgumentException
{
    public function __construct(string $what, public readonly SolvencyPosition $position, ?string $problem = null)
    {
        parent::__construct('Refused: '.$what.' would leave Local Goods holding less money than it has promised ('
            .($problem ?? $position->problem()).'). Nothing was changed. See Where the money is.');
    }
}
