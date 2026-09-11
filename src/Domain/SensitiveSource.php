<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class SensitiveSource
{
    public function __construct(
        public string $model,
        public string $field,
        public Confidence $confidence,
        public string $reason,
    ) {}
}
