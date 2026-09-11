<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class RuleId implements \Stringable
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[A-Z][A-Z0-9-]+$/', $value)) {
            throw new \InvalidArgumentException("Invalid Guardian rule id: {$value}");
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
