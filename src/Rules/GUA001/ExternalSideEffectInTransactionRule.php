<?php

declare(strict_types=1);

namespace Guardian\Rules\GUA001;

use Guardian\Analysis\AnalysisContext;
use Guardian\Analysis\CallResolver;
use Guardian\Analysis\EffectRegistry;
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

final class ExternalSideEffectInTransactionRule implements Rule
{
    private AstPrinter $printer;

    public function __construct()
    {
        $this->printer = new AstPrinter();
    }

    public function id(): RuleId
    {
        return new RuleId('GUA-001');
    }

    public function title(): string
    {
        return 'External side effect inside database transaction';
    }

    public function defaultSeverity(): Severity
    {
        return Severity::CRITICAL;
    }

    public function analyse(ProgramIndex $program, AnalysisContext $context): iterable
    {
        $findings = [];
        $effects = new EffectRegistry($context->config, $context->registry->effectProviders());
        $resolver = new CallResolver($program);
        $maxDepth = max(1, (int) $context->config->get('max_call_depth', 12));

        foreach ($program->methods() as $method) {
            if ($method->node->stmts === null) {
                continue;
            }

            $types = new TypeEnvironment($program, $method);
            $this->inspectStatements(
                $method->node->stmts,
                $method,
                $program,
                $context,
                $effects,
                $resolver,
                $types,
                false,
                null,
                [$method->id()],
                [$method->id() => true],
                0,
                $maxDepth,
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
     * @param list<string> $callPath
     * @param array<string, bool> $callStack
     * @param list<Finding> $findings
     */
    private function inspectStatements(
        array $statements,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        EffectRegistry $effects,
        CallResolver $resolver,
        TypeEnvironment $types,
        bool $inTransaction,
        ?Location $transactionLocation,
        array $callPath,
        array $callStack,
        int $depth,
        int $maxDepth,
        array &$findings,
    ): void {
        $active = $inTransaction;
        $txLocation = $transactionLocation;

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\TryCatch) {
                $this->inspectStatements($statement->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                foreach ($statement->catches as $catch) {
                    $this->inspectStatements($catch->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                }
                if ($statement->finally !== null) {
                    $this->inspectStatements($statement->finally->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                }
                continue;
            }

            if ($statement instanceof Node\Stmt\If_) {
                $this->inspectNode($statement->cond, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                $this->inspectStatements($statement->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                foreach ($statement->elseifs as $elseif) {
                    $this->inspectNode($elseif->cond, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                    $this->inspectStatements($elseif->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                }
                if ($statement->else !== null) {
                    $this->inspectStatements($statement->else->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                }
                continue;
            }

            if ($statement instanceof Node\Stmt\Foreach_ || $statement instanceof Node\Stmt\For_ || $statement instanceof Node\Stmt\While_ || $statement instanceof Node\Stmt\Do_) {
                foreach ($statement->getSubNodeNames() as $subName) {
                    if ($subName === 'stmts') {
                        continue;
                    }
                    $child = $statement->{$subName};
                    if ($child instanceof Node || is_array($child)) {
                        $this->inspectNodeValue($child, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                    }
                }
                $this->inspectStatements($statement->stmts, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                continue;
            }

            $this->inspectNode($statement, $method, $program, $context, $effects, $resolver, $types, $active, $txLocation, $callPath, $callStack, $depth, $maxDepth, $findings);

            if ($this->containsCallNamed($statement, 'beginTransaction')) {
                $active = true;
                $txLocation = new Location($method->file, $statement->getStartLine(), $method->id());
            }
            if ($this->containsCallNamed($statement, 'commit') || $this->containsCallNamed($statement, 'rollBack')) {
                $active = false;
                $txLocation = null;
            }
        }
    }

    /** @param list<string> $callPath @param array<string, bool> $callStack @param list<Finding> $findings */
    private function inspectNodeValue(
        Node|array $value,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        EffectRegistry $effects,
        CallResolver $resolver,
        TypeEnvironment $types,
        bool $inTransaction,
        ?Location $transactionLocation,
        array $callPath,
        array $callStack,
        int $depth,
        int $maxDepth,
        array &$findings,
    ): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof Node) {
                    $this->inspectNode($item, $method, $program, $context, $effects, $resolver, $types, $inTransaction, $transactionLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                }
            }
            return;
        }
        $this->inspectNode($value, $method, $program, $context, $effects, $resolver, $types, $inTransaction, $transactionLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
    }

    /** @param list<string> $callPath @param array<string, bool> $callStack @param list<Finding> $findings */
    private function inspectNode(
        Node $node,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        EffectRegistry $effects,
        CallResolver $resolver,
        TypeEnvironment $types,
        bool $inTransaction,
        ?Location $transactionLocation,
        array $callPath,
        array $callStack,
        int $depth,
        int $maxDepth,
        array &$findings,
    ): void {
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            return;
        }

        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && $this->isTransactionCall($node, $types)) {
            $callback = $node->args[0]->value ?? null;
            $entry = new Location($method->file, $node->getStartLine(), $method->id());
            if ($callback instanceof Node\Expr\Closure) {
                $this->inspectStatements($callback->stmts, $method, $program, $context, $effects, $resolver, $types, true, $entry, $callPath, $callStack, $depth, $maxDepth, $findings);
            } elseif ($callback instanceof Node\Expr\ArrowFunction) {
                $this->inspectNode($callback->expr, $method, $program, $context, $effects, $resolver, $types, true, $entry, $callPath, $callStack, $depth, $maxDepth, $findings);
            }

            foreach ($node->args as $index => $arg) {
                if ($index !== 0) {
                    $this->inspectNode($arg->value, $method, $program, $context, $effects, $resolver, $types, $inTransaction, $transactionLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                }
            }
            return;
        }

        if ($node instanceof Node\Expr) {
            $effect = $effects->detect($node, $method, $types);
            if ($inTransaction && $effect !== null && ! $effect->deferredAfterCommit) {
                $expression = $this->expressionString($node);
                $location = new Location($method->file, $node->getStartLine(), $method->id());
                $trace = $callPath;
                $trace[] = $effect->sink.' @ '.$location->line;
                $findings[] = new Finding(
                    $this->id(),
                    $effect->severity,
                    $effect->confidence,
                    $location,
                    $this->title(),
                    "{$effect->type} side effect '{$effect->sink}' executes while a database transaction is active.",
                    'The external operation may succeed irreversibly while the database transaction later rolls back, leaving systems inconsistent.',
                    array_values(array_filter([
                        'Effect: '.$expression,
                        $transactionLocation ? 'Transaction entered at '.$transactionLocation->file.':'.$transactionLocation->line : null,
                    ])),
                    $trace,
                    [
                        'Move the side effect after commit when the workflow permits it.',
                        'For queued work, use afterCommit() or a queue connection configured with after_commit=true.',
                        'For durable cross-system workflows, prefer a transactional outbox or explicit state machine.',
                    ],
                    Fingerprint::make([
                        'rule' => 'GUA-001',
                        'symbol' => $method->id(),
                        'sink' => $effect->sink,
                        'effect' => $effect->type,
                        'expression' => preg_replace('/\s+/', '', $expression),
                    ]),
                    ['atomicity', 'transactions', strtolower($effect->type)],
                );
            }

            if ($inTransaction && $depth < $maxDepth && ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)) {
                $target = $resolver->resolve($node, $method, $types);
                if ($target !== null && ! isset($callStack[$target->id()]) && $target->node->stmts !== null) {
                    $targetTypes = new TypeEnvironment($program, $target);
                    $nextPath = [...$callPath, $target->id()];
                    $nextStack = $callStack;
                    $nextStack[$target->id()] = true;
                    $this->inspectStatements($target->node->stmts, $target, $program, $context, $effects, $resolver, $targetTypes, true, $transactionLocation, $nextPath, $nextStack, $depth + 1, $maxDepth, $findings);
                }
            }
        }

        foreach ($node->getSubNodeNames() as $subName) {
            $child = $node->{$subName};
            if ($child instanceof Node) {
                $this->inspectNode($child, $method, $program, $context, $effects, $resolver, $types, $inTransaction, $transactionLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->inspectNode($item, $method, $program, $context, $effects, $resolver, $types, $inTransaction, $transactionLocation, $callPath, $callStack, $depth, $maxDepth, $findings);
                    }
                }
            }
        }
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
        if ($type !== null && (str_contains($type, 'Connection') || str_contains($type, 'Database'))) {
            return true;
        }

        return $call->var instanceof Node\Expr\MethodCall
            && $call->var->name instanceof Node\Identifier
            && strcasecmp($call->var->name->toString(), 'connection') === 0;
    }

    private function containsCallNamed(Node $node, string $name): bool
    {
        return NodeInspector::contains($node, static fn (Node $candidate): bool =>
            ($candidate instanceof Node\Expr\MethodCall || $candidate instanceof Node\Expr\StaticCall)
            && $candidate->name instanceof Node\Identifier
            && strcasecmp($candidate->name->toString(), $name) === 0
        );
    }

    private function expressionString(Node\Expr $expr): string
    {
        try {
            return $this->printer->expr($expr);
        } catch (\Throwable) {
            return $expr::class;
        }
    }
}
