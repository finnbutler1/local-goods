<?php

namespace App\Support\Projections;

use InvalidArgumentException;

/**
 * The business model's assumptions for one case (low, base or high), read
 * from config/projections.php (ADR 0030). Projection arithmetic only: it
 * never reads the live fee settings, the ledger or Stripe.
 */
final class Assumptions
{
    public const CASES = ['low', 'base', 'high'];

    /** @param array<string, mixed> $config the whole projections config */
    public function __construct(private readonly array $config, public readonly string $case = 'base')
    {
        if (! in_array($case, self::CASES, true)) {
            throw new InvalidArgumentException("Unknown case \"{$case}\".");
        }
    }

    public function value(string $key): float
    {
        $assumption = $this->config['assumptions'][$key] ?? throw new InvalidArgumentException("Unknown assumption \"{$key}\".");

        return (float) $assumption[$this->case];
    }

    /** @return list<int> BIDs running at the end of each year */
    public function bidsByYearEnd(string $scenario): array
    {
        return $this->scenario($scenario)['bids_by_year_end'];
    }

    /** @return array<string, mixed> */
    public function scenario(string $scenario): array
    {
        return $this->config['scenarios'][$scenario] ?? throw new InvalidArgumentException("Unknown scenario \"{$scenario}\".");
    }

    public function cardIssuingFrom(): ?int
    {
        $issuing = $this->config['card_issuing'] ?? [];

        return ($issuing['enabled'] ?? false) ? (int) $issuing['start_month'] : null;
    }

    public function horizonMonths(): int
    {
        return (int) $this->config['horizon_months'];
    }

    public function searchMonths(): int
    {
        return (int) $this->config['break_even_search_months'];
    }

    /** The AI cost of one Redemption Fraud Detection check: estimated tokens at Anthropic's per-token prices. */
    public function aiCostPerCheck(): float
    {
        return ($this->value('ai_input_tokens_per_check') * $this->value('claude_input_price')
            + $this->value('ai_output_tokens_per_check') * $this->value('claude_output_price')) / 1_000_000;
    }
}
