<?php

declare(strict_types=1);

namespace Guardian\Domain;

enum Confidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::HIGH => 30,
            self::MEDIUM => 20,
            self::LOW => 10,
        };
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::MEDIUM;
    }
}
