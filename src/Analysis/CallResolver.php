<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\Support\TypeEnvironment;
use PhpParser\Node;

final readonly class CallResolver
{
    public function __construct(private ProgramIndex $program) {}

    public function resolve(Node\Expr\MethodCall|Node\Expr\StaticCall $call, MethodInfo $current, TypeEnvironment $types): ?MethodInfo
    {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }
        $method = $call->name->toString();

        if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name) {
            return $this->program->method(ltrim($call->class->toString(), '\\'), $method);
        }

        if ($call instanceof Node\Expr\MethodCall) {
            $target = $types->typeOfExpr($call->var);
            if ($target !== null) {
                return $this->program->method($target, $method);
            }
        }

        return null;
    }
}
