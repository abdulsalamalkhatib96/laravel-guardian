<?php

declare(strict_types=1);

namespace Guardian\Analysis\Facts;

interface GuardianFact
{
    public function methodId(): string;
    public function file(): string;
    public function line(): int;
}
