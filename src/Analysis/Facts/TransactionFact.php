<?php

declare(strict_types=1);

namespace Guardian\Analysis\Facts;

final readonly class TransactionFact implements GuardianFact
{
    public function __construct(
        private string $methodId,
        private string $file,
        private int $line,
        public string $action,
    ) {}

    public function methodId(): string { return $this->methodId; }
    public function file(): string { return $this->file; }
    public function line(): int { return $this->line; }
}
