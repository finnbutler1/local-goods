<?php

namespace App\Support;

use LogicException;

/**
 * The DC sales tax on one redemption or pay code (ADR 0027), as snapshotted
 * when it was made. Two kinds:
 * - added: menu items, with tax worked out per category and added on top
 *   (subtotal + tax = total);
 * - included: a typed-in amount, the shop's own total with any tax already
 *   in it, so nothing is added and nothing is split out.
 * A row from before tax display has no breakdown at all (null basis).
 */
final readonly class TaxBreakdown
{
    public const ADDED = 'added';

    public const INCLUDED = 'included';

    /**
     * @param  list<array{category: string, label: string, rate_bps: int, taxable_cents: int, tax_cents: int}>  $lines
     * @param  list<array<string, mixed>>  $items  the items, each with tax_category and tax_rate_bps
     */
    public function __construct(
        public string $basis,
        public int $totalCents,
        public ?int $subtotalCents = null,
        public ?int $taxCents = null,
        public array $lines = [],
        public array $items = [],
    ) {
        if ($basis === self::ADDED && $subtotalCents + $taxCents !== $totalCents) {
            throw new LogicException('Subtotal and tax must add up to the total.');
        }
    }

    /** A typed-in amount: the shop's total, any tax inside it. */
    public static function included(int $amountCents): self
    {
        return new self(self::INCLUDED, $amountCents);
    }

    /**
     * Rebuild from stored columns (a redemption or pay code), or null for a
     * row from before tax display.
     *
     * @param  array<string, mixed>  $row  tax_basis, amount_cents, subtotal_cents, tax_cents, tax_lines
     */
    public static function fromStored(array $row, array $items = []): ?self
    {
        return match ($row['tax_basis'] ?? null) {
            self::ADDED => new self(self::ADDED, (int) $row['amount_cents'], (int) $row['subtotal_cents'], (int) $row['tax_cents'], $row['tax_lines'] ?? [], $items),
            self::INCLUDED => new self(self::INCLUDED, (int) $row['amount_cents'], items: $items),
            default => null,
        };
    }

    public function isAdded(): bool
    {
        return $this->basis === self::ADDED;
    }

    /** The four stored columns. */
    public function columns(): array
    {
        return [
            'tax_basis' => $this->basis,
            'subtotal_cents' => $this->subtotalCents,
            'tax_cents' => $this->taxCents,
            'tax_lines' => $this->isAdded() ? $this->lines : null,
        ];
    }

    /** "10%", "6.5%" */
    public static function percent(int $rateBps): string
    {
        return rtrim(rtrim(Money::toDollars($rateBps), '0'), '.').'%';
    }
}
