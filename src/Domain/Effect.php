<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class Effect
{
    public function __construct(
        public string $type,
        public string $sink,
        public Severity $severity,
        public Confidence $confidence = Confidence::HIGH,
        public bool $deferredAfterCommit = false,
    ) {}
}
