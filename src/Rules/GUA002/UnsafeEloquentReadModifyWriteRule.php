<?php

declare(strict_types=1);

namespace Guardian\Rules\GUA002;

use Guardian\Analysis\AnalysisContext;
use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\ProgramIndex;
use Guardian\Analysis\Support\AstPrinter;
use Guardian\Analysis\Support\NodeInspector;
use Guardian\Analysis\Support\NodeName;
use Guardian\Analysis\Support\TypeEnvironment;
use Guardian\Contracts\Rule;
use Guardian\Domain\Confidence;
use Guardian\Domain\Finding;
use Guardian\Domain\Fingerprint;
use Guardian\Domain\Location;
use Guardian\Domain\RuleId;
use Guardian\Domain\Severity;
use PhpParser\Node;

final class UnsafeEloquentReadModifyWriteRule implements Rule
{
    private AstPrinter $printer;

    public function __construct()
    {
        $this->printer = new AstPrinter();
    }

    public function id(): RuleId
    {
        return new RuleId('GUA-002');
    }

    public function title(): string
    {
        return 'Unsafe Eloquent read-modify-write';
    }

    public function defaultSeverity(): Severity
    {
        return Severity::HIGH;
    }

    public function analyse(ProgramIndex $program, AnalysisContext $context): iterable
    {
        $findings = [];

        foreach ($program->methods() as $method) {
            if ($method->node->stmts === null) {
                continue;
            }

            $types = new TypeEnvironment($program, $method);
            $aliases = $this->collectReadAliases($method->node->stmts);
            $this->scanStatements(
                $method->node->stmts,
                $method,
                $program,
                $context,
                $types,
                $aliases,
                false,
                false,
                $findings,
            );
        }

        $unique = [];
        foreach ($findings as $finding) {
            $unique[(string) $finding->fingerprint] ??= $finding;
        }

        return array_values($unique);
    }

    /**
     * @param list<Node\Stmt> $statements
     * @param array<string, list<string>> $aliases
     * @param list<Finding> $findings
     */
    private function scanStatements(
        array $statements,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        TypeEnvironment $types,
        array $aliases,
        bool $inTransaction,
        bool $inDistributedLock,
        array &$findings,
    ): void {
        $activeTransaction = $inTransaction;

        foreach ($statements as $offset => $statement) {
            if ($statement instanceof Node\Stmt\Expression
                && ($statement->expr instanceof Node\Expr\MethodCall || $statement->expr instanceof Node\Expr\StaticCall)
                && $this->isTransactionCall($statement->expr, $types)) {
                $callback = $statement->expr->args[0]->value ?? null;
                if ($callback instanceof Node\Expr\Closure) {
                    $this->scanStatements($callback->stmts, $method, $program, $context, $types, $aliases, true, $inDistributedLock, $findings);
                }
                continue;
            }

            $transactionCall = $this->findTransactionCallback($statement, $types);
            if ($transactionCall instanceof Node\Expr\Closure) {
                $this->scanStatements($transactionCall->stmts, $method, $program, $context, $types, $aliases, true, $inDistributedLock, $findings);
            }

            $lockCallback = $this->findDistributedLockCallback($statement, $context);
            if ($lockCallback instanceof Node\Expr\Closure) {
                $this->scanStatements($lockCallback->stmts, $method, $program, $context, $types, $aliases, $activeTransaction, true, $findings);
            }

            if ($statement instanceof Node\Stmt\If_) {
                $this->detectCheckThenAct($statement, $method, $program, $context, $types, $activeTransaction, $inDistributedLock, $findings);
                $this->scanStatements($statement->stmts, $method, $program, $context, $types, $aliases, $activeTransaction, $inDistributedLock, $findings);
                foreach ($statement->elseifs as $elseif) {
                    $this->scanStatements($elseif->stmts, $method, $program, $context, $types, $aliases, $activeTransaction, $inDistributedLock, $findings);
                }
                if ($statement->else !== null) {
                    $this->scanStatements($statement->else->stmts, $method, $program, $context, $types, $aliases, $activeTransaction, $inDistributedLock, $findings);
                }
            }

            foreach ($this->mutationCandidates($statement, $aliases) as $candidate) {
                [$var, $field, $node, $kind] = $candidate;
                if (! $this->isEloquentVariable($var, $types, $program, $node->getStartLine())) {
                    continue;
                }
                if (! $this->hasPersistence($statements, $offset, $var, $field, $node, $aliases)) {
                    continue;
                }
                if ($this->protected($var, $types, $context, $activeTransaction, $inDistributedLock, $node->getStartLine())) {
                    continue;
                }

                $model = $types->typeOfVariableAt($var, $node->getStartLine()) ?? 'unknown-model';
                $severity = $this->severity($model, $field, $context);
                $hasUnscopedLock = $types->isLockedVariableAt($var, $node->getStartLine()) && ! $activeTransaction;
                $expression = $node instanceof Node\Expr ? $this->safePrint($node) : $node::class;

                $findings[] = new Finding(
                    $this->id(),
                    $severity,
                    Confidence::HIGH,
                    new Location($method->file, $node->getStartLine(), $method->id()),
                    $kind === 'derived-atomic-write' ? 'Atomic write depends on stale Eloquent state' : $this->title(),
                    "{$model}::\${$field} is read and used to derive a later write without proven concurrency protection.",
                    'Concurrent requests may read the same stale value and overwrite or derive conflicting state, causing lost updates or invalid state transitions.',
                    array_values(array_filter([
                        'Expression: '.$expression,
                        'Model variable: $'.$var,
                        'Field: '.$field,
                        $hasUnscopedLock ? 'lockForUpdate() was detected, but no active transaction protects the read/write window.' : null,
                    ])),
                    [$method->id(), '$'.$var.'->'.$field, $kind],
                    [
                        'Use lockForUpdate() inside the same database transaction that performs the read and write.',
                        'Use a true atomic increment/decrement when the operation is a fixed delta independent of a stale read.',
                        'For state transitions, use compare-and-swap: constrain the UPDATE by the expected old state and verify the affected-row count.',
                    ],
                    Fingerprint::make([
                        'rule' => 'GUA-002',
                        'symbol' => $method->id(),
                        'model' => $model,
                        'field' => $field,
                        'kind' => $kind,
                        'expression' => preg_replace('/\s+/', '', $expression),
                    ]),
                    ['concurrency', 'eloquent', 'read-modify-write'],
                );
            }

            if ($this->containsCallNamed($statement, 'beginTransaction')) {
                $activeTransaction = true;
            }
            if ($this->containsCallNamed($statement, 'commit') || $this->containsCallNamed($statement, 'rollBack')) {
                $activeTransaction = false;
            }
        }
    }

    /**
     * @param array<string, list<string>> $aliases
     * @return list<array{0:string,1:string,2:Node,3:string}>
     */
    private function mutationCandidates(Node $node, array $aliases): array
    {
        $result = [];
        NodeInspector::walkCurrentScope($node, function (Node $candidate) use (&$result, $aliases): void {
            if ($candidate instanceof Node\Expr\AssignOp\Plus || $candidate instanceof Node\Expr\AssignOp\Minus) {
                $property = $this->propertyIdentity($candidate->var);
                if ($property !== null) {
                    $result[] = [$property[0], $property[1], $candidate, 'arithmetic-rmw'];
                }
                return;
            }

            if ($candidate instanceof Node\Expr\PreInc || $candidate instanceof Node\Expr\PostInc || $candidate instanceof Node\Expr\PreDec || $candidate instanceof Node\Expr\PostDec) {
                $property = $this->propertyIdentity($candidate->var);
                if ($property !== null) {
                    $result[] = [$property[0], $property[1], $candidate, 'arithmetic-rmw'];
                }
                return;
            }

            if ($candidate instanceof Node\Expr\Assign) {
                $property = $this->propertyIdentity($candidate->var);
                if ($property !== null && $this->expressionDependsOn($candidate->expr, $property[0], $property[1], $aliases)) {
                    $result[] = [$property[0], $property[1], $candidate, 'derived-assignment'];
                }
                return;
            }

            if ($candidate instanceof Node\Expr\MethodCall
                && $candidate->var instanceof Node\Expr\Variable
                && is_string($candidate->var->name)
                && $candidate->name instanceof Node\Identifier
                && in_array(strtolower($candidate->name->toString()), ['increment', 'decrement'], true)) {
                $fieldArg = $candidate->args[0]->value ?? null;
                $amountArg = $candidate->args[1]->value ?? null;
                if ($fieldArg instanceof Node\Scalar\String_ && $amountArg instanceof Node\Expr
                    && $this->expressionDependsOn($amountArg, $candidate->var->name, $fieldArg->value, $aliases)) {
                    $result[] = [$candidate->var->name, $fieldArg->value, $candidate, 'derived-atomic-write'];
                }
            }
        });

        return $result;
    }

    /** @return array{0:string,1:string}|null */
    private function propertyIdentity(Node\Expr $expr): ?array
    {
        if (! $expr instanceof Node\Expr\PropertyFetch
            || ! $expr->var instanceof Node\Expr\Variable
            || ! is_string($expr->var->name)
            || ! $expr->name instanceof Node\Identifier) {
            return null;
        }

        return [$expr->var->name, $expr->name->toString()];
    }

    /** @param array<string, list<string>> $aliases */
    private function expressionDependsOn(Node\Expr $expr, string $var, string $field, array $aliases): bool
    {
        $needle = strtolower($var.'.'.$field);
        $depends = false;
        NodeInspector::walkCurrentScope($expr, function (Node $node) use (&$depends, $needle, $aliases): void {
            if ($depends) {
                return;
            }
            if ($node instanceof Node\Expr\PropertyFetch) {
                $identity = $this->propertyIdentity($node);
                if ($identity !== null && strtolower($identity[0].'.'.$identity[1]) === $needle) {
                    $depends = true;
                    return;
                }
            }
            if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                foreach ($aliases[$node->name] ?? [] as $dependency) {
                    if (strtolower($dependency) === $needle) {
                        $depends = true;
                        return;
                    }
                }
            }
        });

        return $depends;
    }

    /** @param list<Node\Stmt> $statements @param array<string, list<string>> $aliases */
    private function hasPersistence(array $statements, int $offset, string $var, string $field, Node $mutation, array $aliases): bool
    {
        if ($mutation instanceof Node\Expr\MethodCall
            && $mutation->name instanceof Node\Identifier
            && in_array(strtolower($mutation->name->toString()), ['increment', 'decrement'], true)) {
            return true;
        }

        for ($i = $offset; $i < count($statements); $i++) {
            if (NodeInspector::containsCurrentScope($statements[$i], static function (Node $node) use ($var): bool {
                return $node instanceof Node\Expr\MethodCall
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === $var
                    && $node->name instanceof Node\Identifier
                    && in_array(strtolower($node->name->toString()), ['save', 'savequietly', 'update', 'push'], true);
            })) {
                return true;
            }
        }

        return false;
    }

    private function detectCheckThenAct(
        Node\Stmt\If_ $if,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        TypeEnvironment $types,
        bool $inTransaction,
        bool $inDistributedLock,
        array &$findings,
    ): void {
        $reads = [];
        NodeInspector::walkCurrentScope($if->cond, function (Node $node) use (&$reads): void {
            if ($node instanceof Node\Expr\PropertyFetch) {
                $identity = $this->propertyIdentity($node);
                if ($identity !== null) {
                    $reads[strtolower($identity[0].'.'.$identity[1])] = $identity;
                }
            }
        });

        foreach ($reads as [$var, $field]) {
            if (! $this->isEloquentVariable($var, $types, $program, $if->getStartLine())) {
                continue;
            }
            $writesField = NodeInspector::containsCurrentScope($if->stmts, function (Node $node) use ($var, $field): bool {
                if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec) {
                    $target = $node->var;
                    if ($target instanceof Node\Expr) {
                        $identity = $this->propertyIdentity($target);
                        return $identity !== null && $identity[0] === $var && strcasecmp($identity[1], $field) === 0;
                    }
                }
                return false;
            });
            $persists = NodeInspector::containsCurrentScope($if->stmts, static function (Node $node) use ($var): bool {
                return $node instanceof Node\Expr\MethodCall
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === $var
                    && $node->name instanceof Node\Identifier
                    && in_array(strtolower($node->name->toString()), ['save', 'savequietly', 'update', 'push'], true);
            });

            if (! $writesField || ! $persists || $this->protected($var, $types, $context, $inTransaction, $inDistributedLock, $if->getStartLine())) {
                continue;
            }

            $model = $types->typeOfVariableAt($var, $if->getStartLine()) ?? 'unknown-model';
            $condition = $this->safePrint($if->cond);
            $findings[] = new Finding(
                $this->id(),
                $this->severity($model, $field, $context),
                Confidence::HIGH,
                new Location($method->file, $if->getStartLine(), $method->id()),
                'Unsafe Eloquent check-then-act state transition',
                "A decision reads {$model}::\${$field}, then writes that state without proven concurrency protection.",
                'Two workers can observe the same precondition as true and both perform the transition or its side effects.',
                [
                    'Condition: '.$condition,
                    'State: $'.$var.'->'.$field,
                ],
                [$method->id(), 'if('.$condition.')', '$'.$var.'->'.$field],
                [
                    'Lock the row with lockForUpdate() inside a transaction before checking the state.',
                    'Prefer a compare-and-swap UPDATE constrained by the expected state and verify exactly one row changed.',
                ],
                Fingerprint::make([
                    'rule' => 'GUA-002',
                    'symbol' => $method->id(),
                    'model' => $model,
                    'field' => $field,
                    'kind' => 'check-then-act',
                    'condition' => preg_replace('/\s+/', '', $condition),
                ]),
                ['concurrency', 'eloquent', 'check-then-act'],
            );
        }
    }

    /** @param list<Node\Stmt> $statements @return array<string, list<string>> */
    private function collectReadAliases(array $statements): array
    {
        $aliases = [];
        $changed = true;
        $passes = 0;

        while ($changed && $passes++ < 6) {
            $changed = false;
            NodeInspector::walkCurrentScope($statements, function (Node $node) use (&$aliases, &$changed): void {
                if (! $node instanceof Node\Expr\Assign
                    || ! $node->var instanceof Node\Expr\Variable
                    || ! is_string($node->var->name)) {
                    return;
                }

                $deps = [];
                NodeInspector::walkCurrentScope($node->expr, function (Node $expr) use (&$deps, $aliases): void {
                    if ($expr instanceof Node\Expr\PropertyFetch) {
                        $identity = $this->propertyIdentity($expr);
                        if ($identity !== null) {
                            $deps[] = strtolower($identity[0].'.'.$identity[1]);
                        }
                    }
                    if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                        array_push($deps, ...($aliases[$expr->name] ?? []));
                    }
                });
                $deps = array_values(array_unique($deps));
                if ($deps !== [] && ($aliases[$node->var->name] ?? []) !== $deps) {
                    $aliases[$node->var->name] = $deps;
                    $changed = true;
                }
            });
        }

        return $aliases;
    }

    private function isEloquentVariable(string $var, TypeEnvironment $types, ProgramIndex $program, int $line): bool
    {
        $type = $types->typeOfVariableAt($var, $line);
        return $type !== null && $program->isModelClass($type);
    }

    private function protected(string $var, TypeEnvironment $types, AnalysisContext $context, bool $inTransaction, bool $inDistributedLock, int $line): bool
    {
        if ($inTransaction && $types->isLockedVariableAt($var, $line)) {
            return true;
        }

        return $inDistributedLock && $context->config->get('rules.GUA-002.trusted_distributed_locks', false) === true;
    }

    private function severity(string $model, string $field, AnalysisContext $context): Severity
    {
        foreach ((array) $context->config->get('rules.GUA-002.critical_fields', []) as $configuredModel => $fields) {
            if (! is_string($configuredModel) || ! is_array($fields) || strcasecmp(ltrim($configuredModel, '\\'), ltrim($model, '\\')) !== 0) {
                continue;
            }
            foreach ($fields as $criticalField) {
                if (is_string($criticalField) && strcasecmp($criticalField, $field) === 0) {
                    return Severity::CRITICAL;
                }
            }
        }

        return Severity::HIGH;
    }

    private function findTransactionCallback(Node $node, TypeEnvironment $types): ?Node\Expr\Closure
    {
        $callback = null;
        NodeInspector::walkCurrentScope($node, function (Node $candidate) use (&$callback, $types): void {
            if ($callback !== null || ! ($candidate instanceof Node\Expr\MethodCall || $candidate instanceof Node\Expr\StaticCall)) {
                return;
            }
            if ($this->isTransactionCall($candidate, $types)) {
                $value = $candidate->args[0]->value ?? null;
                if ($value instanceof Node\Expr\Closure) {
                    $callback = $value;
                }
            }
        });
        return $callback;
    }

    private function findDistributedLockCallback(Node $node, AnalysisContext $context): ?Node\Expr\Closure
    {
        $callback = null;
        NodeInspector::walkCurrentScope($node, function (Node $candidate) use (&$callback, $context): void {
            if ($callback !== null || ! $candidate instanceof Node\Expr\MethodCall || ! $candidate->name instanceof Node\Identifier) {
                return;
            }
            if (! in_array(strtolower($candidate->name->toString()), ['block', 'get'], true)) {
                return;
            }
            $builtIn = NodeInspector::containsCurrentScope($candidate->var, static fn (Node $n): bool =>
                $n instanceof Node\Expr\StaticCall
                && $n->class instanceof Node\Name
                && (str_ends_with($n->class->toString(), '\\Cache') || strcasecmp($n->class->toString(), 'Cache') === 0)
                && $n->name instanceof Node\Identifier
                && strcasecmp($n->name->toString(), 'lock') === 0
            );
            $custom = false;
            if ($candidate instanceof Node\Expr) {
                foreach ($context->registry->concurrencyGuards() as $provider) {
                    if ($provider->protects($candidate)) {
                        $custom = true;
                        break;
                    }
                }
            }
            if (! $builtIn && ! $custom) {
                return;
            }
            foreach ($candidate->args as $arg) {
                if ($arg->value instanceof Node\Expr\Closure) {
                    $callback = $arg->value;
                    break;
                }
            }
        });
        return $callback;
    }

    private function isTransactionCall(Node\Expr\MethodCall|Node\Expr\StaticCall $call, TypeEnvironment $types): bool
    {
        if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'transaction') !== 0) {
            return false;
        }
        if ($call instanceof Node\Expr\StaticCall) {
            $class = NodeName::of($call->class);
            return $class !== null && (strcasecmp($class, 'DB') === 0 || str_ends_with($class, '\\DB') || str_contains($class, 'Database'));
        }
        $type = $types->typeOfExpr($call->var);
        return ($type !== null && (str_contains($type, 'Connection') || str_contains($type, 'Database')))
            || ($call->var instanceof Node\Expr\MethodCall && $call->var->name instanceof Node\Identifier && strcasecmp($call->var->name->toString(), 'connection') === 0);
    }

    private function containsCallNamed(Node $node, string $name): bool
    {
        return NodeInspector::containsCurrentScope($node, static fn (Node $candidate): bool =>
            ($candidate instanceof Node\Expr\MethodCall || $candidate instanceof Node\Expr\StaticCall)
            && $candidate->name instanceof Node\Identifier
            && strcasecmp($candidate->name->toString(), $name) === 0
        );
    }

    private function safePrint(Node\Expr $expr): string
    {
        try {
            return $this->printer->expr($expr);
        } catch (\Throwable) {
            return $expr::class;
        }
    }
}
