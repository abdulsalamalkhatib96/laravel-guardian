<?php

declare(strict_types=1);

namespace Guardian\Contracts;

use PhpParser\Node\Expr;

interface ConcurrencyGuardProvider
{
    public function protects(Expr $expression): bool;
}
