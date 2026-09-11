<?php

declare(strict_types=1);

namespace Guardian\Analysis\Facts;

final readonly class PropertyWriteFact implements GuardianFact
{
    public function __construct(
        private string $methodId,
        private string $file,
        private int $line,
        public string $variable,
        public string $property,
        public string $kind,
    ) {}

    public function methodId(): string { return $this->methodId; }
    public function file(): string { return $this->file; }
    public function line(): int { return $this->line; }
}
