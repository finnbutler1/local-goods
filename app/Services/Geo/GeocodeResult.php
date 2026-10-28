<?php

namespace App\Services\Geo;

/**
 * One answer about an address. "unavailable" means the address service
 * could not be reached or answered oddly: never stored, never inside.
 */
final class GeocodeResult
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $status,
        public readonly ?string $matchedAddress = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?int $marAddressId = null,
        public readonly ?string $hint = null,
    ) {}

    public static function found(string $matchedAddress, float $latitude, float $longitude, ?int $marAddressId): self
    {
        return new self(self::FOUND, $matchedAddress, $latitude, $longitude, $marAddressId);
    }

    public static function notFound(?string $hint = null): self
    {
        return new self(self::NOT_FOUND, hint: $hint);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
