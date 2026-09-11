<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class Fingerprint implements \Stringable
{
    public function __construct(public string $value) {}

    /** @param array<string, scalar|null> $parts */
    public static function make(array $parts): self
    {
        ksort($parts);
        $normalized = array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $parts,
        );

        return new self(hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)));
    }

    public function short(): string
    {
        return substr($this->value, 0, 12);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
