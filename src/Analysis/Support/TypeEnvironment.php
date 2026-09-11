<?php

declare(strict_types=1);

namespace Guardian\Analysis\Support;

use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\ProgramIndex;
use PhpParser\Node;

final class TypeEnvironment
{
    /** @var array<string, string> */
    private array $types;

    /** @var array<string, list<array{line:int,type:?string,locked:bool}>> */
    private array $assignments = [];

    public function __construct(
        private readonly ProgramIndex $program,
        private readonly MethodInfo $method,
    ) {
        $this->types = $method->parameterTypes;
        foreach ($method->parameterTypes as $name => $type) {
            $this->assignments[$name][] = ['line' => $method->line, 'type' => $type, 'locked' => false];
        }
        $this->collectAssignments($method->node->stmts ?? []);
    }

    public function typeOfVariable(string $variable): ?string
    {
        return $this->types[$variable] ?? null;
    }

    public function typeOfVariableAt(string $variable, int $line): ?string
    {
        $assignment = $this->latestAssignmentAt($variable, $line);
        return $assignment['type'] ?? $this->typeOfVariable($variable);
    }

    public function isLockedVariable(string $variable): bool
    {
        $assignments = $this->assignments[$variable] ?? [];
        if ($assignments === []) {
            return false;
        }
        $latest = end($assignments);
        return is_array($latest) && $latest['locked'];
    }

    public function isLockedVariableAt(string $variable, int $line): bool
    {
        $assignment = $this->latestAssignmentAt($variable, $line);
        return $assignment['locked'] ?? false;
    }

    public function typeOfExpr(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            if ($expr->name === 'this') {
                return $this->method->class;
            }
            return $this->typeOfVariableAt($expr->name, max(1, $expr->getStartLine()));
        }

        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier) {
            $class = $this->program->class($this->method->class);
            return $class?->properties[$expr->name->toString()] ?? null;
        }

        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return ltrim($expr->class->toString(), '\\');
        }

        return $this->inferRootStaticClass($expr);
    }

    /** @return array{line:int,type:?string,locked:bool}|null */
    private function latestAssignmentAt(string $variable, int $line): ?array
    {
        $latest = null;
        foreach ($this->assignments[$variable] ?? [] as $assignment) {
            if ($assignment['line'] <= $line && ($latest === null || $assignment['line'] >= $latest['line'])) {
                $latest = $assignment;
            }
        }
        return $latest;
    }

    private function collectAssignments(array $statements): void
    {
        NodeInspector::walk($statements, function (Node $node): void {
            if (! $node instanceof Node\Expr\Assign
                || ! $node->var instanceof Node\Expr\Variable
                || ! is_string($node->var->name)) {
                return;
            }

            $type = $this->inferAssignedType($node->expr);
            if ($type === null) {
                return;
            }

            $name = $node->var->name;
            $this->types[$name] = $type;
            $this->assignments[$name][] = [
                'line' => max(1, $node->getStartLine()),
                'type' => $type,
                'locked' => $this->chainHasMethod($node->expr, 'lockForUpdate'),
            ];
            usort($this->assignments[$name], static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        });
    }

    private function inferAssignedType(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return ltrim($expr->class->toString(), '\\');
        }

        return $this->inferRootStaticClass($expr);
    }

    private function inferRootStaticClass(Node\Expr $expr): ?string
    {
        $current = $expr;
        while ($current instanceof Node\Expr\MethodCall || $current instanceof Node\Expr\NullsafeMethodCall) {
            $current = $current->var;
        }

        if ($current instanceof Node\Expr\StaticCall && $current->class instanceof Node\Name) {
            return ltrim($current->class->toString(), '\\');
        }

        return null;
    }

    public function chainHasMethod(Node\Expr $expr, string $method): bool
    {
        $found = false;
        NodeInspector::walk($expr, static function (Node $node) use ($method, &$found): void {
            if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
                && strcasecmp($node->name->toString(), $method) === 0) {
                $found = true;
            }
        });

        return $found;
    }
}
