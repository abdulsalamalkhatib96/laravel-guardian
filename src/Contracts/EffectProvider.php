<?php

declare(strict_types=1);

namespace Guardian\Contracts;

use Guardian\Analysis\Index\MethodInfo;
use Guardian\Domain\Effect;
use PhpParser\Node\Expr;

interface EffectProvider
{
    public function detect(Expr $expression, MethodInfo $method): ?Effect;
}
