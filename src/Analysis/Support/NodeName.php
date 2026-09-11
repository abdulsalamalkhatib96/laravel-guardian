<?php

declare(strict_types=1);

namespace Guardian\Analysis\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

final class NodeName
{
    public static function of(Node|Name|string|null $node): ?string
    {
        if ($node === null) {
            return null;
        }
        if (is_string($node)) {
            return ltrim($node, '\\');
        }
        if ($node instanceof Name) {
            return ltrim($node->toString(), '\\');
        }
        if ($node instanceof Node\Identifier) {
            return $node->toString();
        }
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name) {
            return ltrim($node->class->toString(), '\\');
        }

        return null;
    }

    public static function method(Expr\MethodCall|Expr\StaticCall|null $call): ?string
    {
        if ($call === null || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        return $call->name->toString();
    }
}
