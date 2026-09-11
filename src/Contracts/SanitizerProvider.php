<?php

declare(strict_types=1);

namespace Guardian\Contracts;

use PhpParser\Node\Expr;

interface SanitizerProvider
{
    public function sanitizes(Expr $expression): bool;
}
