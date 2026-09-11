<?php

declare(strict_types=1);

namespace Guardian\Domain;

enum Severity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case INFO = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::CRITICAL => 50,
            self::HIGH => 40,
            self::MEDIUM => 30,
            self::LOW => 20,
            self::INFO => 10,
        };
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::HIGH;
    }
}
