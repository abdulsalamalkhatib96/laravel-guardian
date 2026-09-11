<?php

declare(strict_types=1);

namespace Guardian\Analysis\Support;

use PhpParser\Node;

final class NodeInspector
{
    /** @param callable(Node): void $callback */
    public static function walk(Node|array|null $node, callable $callback): void
    {
        if ($node === null) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $item) {
                if ($item instanceof Node) {
                    self::walk($item, $callback);
                }
            }
            return;
        }

        $callback($node);
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node || is_array($child)) {
                self::walk($child, $callback);
            }
        }
    }

    /** @param callable(Node): bool $predicate */
    public static function contains(Node|array|null $node, callable $predicate): bool
    {
        $found = false;
        self::walk($node, static function (Node $candidate) use (&$found, $predicate): void {
            if (! $found && $predicate($candidate)) {
                $found = true;
            }
        });

        return $found;
    }


    /**
     * Walk the current executable scope without descending into nested closures or arrow functions.
     * The scope node itself is still visited, but nested function bodies are treated as boundaries.
     *
     * @param callable(Node): void $callback
     */
    public static function walkCurrentScope(Node|array|null $node, callable $callback): void
    {
        self::walkCurrentScopeInternal($node, $callback, true);
    }

    /** @param callable(Node): void $callback */
    private static function walkCurrentScopeInternal(Node|array|null $node, callable $callback, bool $root): void
    {
        if ($node === null) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $item) {
                if ($item instanceof Node) {
                    self::walkCurrentScopeInternal($item, $callback, false);
                }
            }
            return;
        }

        $callback($node);
        if (! $root && ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)) {
            return;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node || is_array($child)) {
                self::walkCurrentScopeInternal($child, $callback, false);
            }
        }
    }

    /** @param callable(Node): bool $predicate */
    public static function containsCurrentScope(Node|array|null $node, callable $predicate): bool
    {
        $found = false;
        self::walkCurrentScope($node, static function (Node $candidate) use (&$found, $predicate): void {
            if (! $found && $predicate($candidate)) {
                $found = true;
            }
        });

        return $found;
    }

    /** @return list<Node> */
    public static function all(Node|array|null $node): array
    {
        $nodes = [];
        self::walk($node, static function (Node $candidate) use (&$nodes): void {
            $nodes[] = $candidate;
        });
        return $nodes;
    }
}
