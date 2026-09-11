<?php

declare(strict_types=1);

namespace Guardian\Rules\GUA003;

use Guardian\Analysis\AnalysisContext;
use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\ProgramIndex;
use Guardian\Analysis\SensitiveRegistry;
use Guardian\Analysis\Support\AstPrinter;
use Guardian\Analysis\Support\NodeInspector;
use Guardian\Analysis\Support\TypeEnvironment;
use Guardian\Contracts\Rule;
use Guardian\Domain\Confidence;
use Guardian\Domain\Finding;
use Guardian\Domain\Fingerprint;
use Guardian\Domain\Location;
use Guardian\Domain\RuleId;
use Guardian\Domain\SensitiveSource;
use Guardian\Domain\Severity;
use PhpParser\Node;

final class SensitiveFieldExposureRule implements Rule
{
    private AstPrinter $printer;

    public function __construct()
    {
        $this->printer = new AstPrinter();
    }

    public function id(): RuleId
    {
        return new RuleId('GUA-003');
    }

    public function title(): string
    {
        return 'Sensitive attribute exposed by an API serialization path';
    }

    public function defaultSeverity(): Severity
    {
        return Severity::HIGH;
    }

    public function analyse(ProgramIndex $program, AnalysisContext $context): iterable
    {
        $registry = new SensitiveRegistry($program, $context->config);
        $findings = [];

        foreach ($program->methods() as $method) {
            if ($method->node->stmts === null) {
                continue;
            }

            $class = $program->class($method->class);
            $isResource = $class?->isJsonResource() === true;
            $resourceModel = $isResource ? $registry->resourceModel($method->class) : null;
            $types = new TypeEnvironment($program, $method);
            $taints = [];
            $madeVisible = [];

            $this->scanStatements(
                $method->node->stmts,
                $method,
                $program,
                $context,
                $registry,
                $types,
                $isResource,
                $resourceModel,
                $taints,
                $madeVisible,
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
     * @param array<string, list<SensitiveSource>> $taints
     * @param array<string, list<string>> $madeVisible
     * @param list<Finding> $findings
     */
    private function scanStatements(
        array $statements,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        SensitiveRegistry $registry,
        TypeEnvironment $types,
        bool $isResource,
        ?string $resourceModel,
        array &$taints,
        array &$madeVisible,
        array &$findings,
    ): void {
        foreach ($statements as $statement) {
            NodeInspector::walk($statement, function (Node $node) use (
                $method,
                $program,
                $context,
                $registry,
                $types,
                $resourceModel,
                &$taints,
                &$madeVisible,
                &$findings,
            ): void {
                if ($node instanceof Node\Expr\Assign
                    && $node->var instanceof Node\Expr\Variable
                    && is_string($node->var->name)) {
                    $sources = $this->sourcesFromExpr($node->expr, $method, $program, $context, $registry, $types, $resourceModel, $taints);
                    if ($sources !== []) {
                        $taints[$node->var->name] = $sources;
                    }
                }

                if ($node instanceof Node\Expr\MethodCall
                    && $node->var instanceof Node\Expr\Variable
                    && is_string($node->var->name)
                    && $node->name instanceof Node\Identifier
                    && in_array(strtolower($node->name->toString()), ['makevisible', 'setvisible'], true)) {
                    foreach ($this->literalStringArguments($node) as $field) {
                        $madeVisible[$node->var->name][] = $field;
                    }
                }

                if ($this->isJsonResponseCall($node)) {
                    $expr = $this->firstPayloadExpression($node);
                    if ($expr instanceof Node\Expr) {
                        $this->emitForExpression($expr, $node, $method, $program, $context, $registry, $types, $resourceModel, $taints, $madeVisible, 'JSON response', $findings);
                    }
                }

                if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name
                    && str_ends_with($node->class->toString(), 'JsonResponse')) {
                    $expr = $node->args[0]->value ?? null;
                    if ($expr instanceof Node\Expr) {
                        $this->emitForExpression($expr, $node, $method, $program, $context, $registry, $types, $resourceModel, $taints, $madeVisible, 'JsonResponse', $findings);
                    }
                }
            });

            if ($statement instanceof Node\Stmt\Return_ && $statement->expr instanceof Node\Expr) {
                if ($isResource) {
                    $this->emitForExpression($statement->expr, $statement, $method, $program, $context, $registry, $types, $resourceModel, $taints, $madeVisible, 'JsonResource::toArray()', $findings);
                } elseif ($this->isDirectSerializedModelExpression($statement->expr, $types, $program, $madeVisible)) {
                    $this->emitForExpression($statement->expr, $statement, $method, $program, $context, $registry, $types, $resourceModel, $taints, $madeVisible, 'returned serialized model', $findings);
                }
            }

            if ($statement instanceof Node\Stmt\If_) {
                $this->scanStatements($statement->stmts, $method, $program, $context, $registry, $types, $isResource, $resourceModel, $taints, $madeVisible, $findings);
                foreach ($statement->elseifs as $elseif) {
                    $this->scanStatements($elseif->stmts, $method, $program, $context, $registry, $types, $isResource, $resourceModel, $taints, $madeVisible, $findings);
                }
                if ($statement->else !== null) {
                    $this->scanStatements($statement->else->stmts, $method, $program, $context, $registry, $types, $isResource, $resourceModel, $taints, $madeVisible, $findings);
                }
            }
        }
    }

    /**
     * @param array<string, list<SensitiveSource>> $taints
     * @param array<string, list<string>> $madeVisible
     * @param list<Finding> $findings
     */
    private function emitForExpression(
        Node\Expr $expr,
        Node $sinkNode,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        SensitiveRegistry $registry,
        TypeEnvironment $types,
        ?string $resourceModel,
        array $taints,
        array $madeVisible,
        string $sink,
        array &$findings,
    ): void {
        $sources = $this->sourcesFromExpr($expr, $method, $program, $context, $registry, $types, $resourceModel, $taints);

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $model = $types->typeOfVariable($expr->name);
            if ($model !== null && $program->isModelClass($model)) {
                foreach ($madeVisible[$expr->name] ?? [] as $field) {
                    $source = $registry->source($model, $field);
                    if ($source !== null) {
                        $sources[] = $source;
                    }
                }
            }
        }

        if ($expr instanceof Node\Expr\MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && $expr->name instanceof Node\Identifier
            && in_array(strtolower($expr->name->toString()), ['makevisible', 'setvisible'], true)) {
            $model = $types->typeOfVariable($expr->var->name);
            foreach ($this->literalStringArguments($expr) as $field) {
                $source = $registry->source($model, $field);
                if ($source !== null) {
                    $sources[] = $source;
                }
            }
        }

        $byKey = [];
        foreach ($sources as $source) {
            $byKey[strtolower($source->model.'.'.$source->field)] = $source;
        }

        foreach ($byKey as $source) {
            $confidence = $this->isAuthorizationConditional($expr) ? Confidence::MEDIUM : $source->confidence;
            $severity = $this->severityForField($source->field);
            $location = new Location($method->file, $sinkNode->getStartLine(), $method->id());
            $expression = $this->safePrint($expr);

            $findings[] = new Finding(
                $this->id(),
                $severity,
                $confidence,
                $location,
                $this->title(),
                "{$source->model}::\${$source->field} reaches {$sink}.",
                'Sensitive data can be unintentionally returned to API consumers, logs, clients, caches, or downstream systems depending on the response path.',
                [
                    'Sensitive field: '.$source->field,
                    'Sensitivity source: '.$source->reason,
                    'Serialization sink: '.$sink,
                    'Expression: '.$expression,
                ],
                [$method->id(), $source->model.'::$'.$source->field, $sink],
                [
                    'Remove the field from the serialized payload when it is not required.',
                    'Mask or transform the value using an explicitly configured Guardian sanitizer.',
                    'If exposure is intentional and authorization-protected, document it with a narrow Guardian suppression and reason.',
                ],
                Fingerprint::make([
                    'rule' => 'GUA-003',
                    'symbol' => $method->id(),
                    'model' => $source->model,
                    'field' => $source->field,
                    'sink' => $sink,
                ]),
                ['sensitive-data', 'api', 'serialization', 'sensitive-field:'.strtolower($source->field)],
            );
        }
    }

    /**
     * @param array<string, list<SensitiveSource>> $taints
     * @return list<SensitiveSource>
     */
    private function sourcesFromExpr(
        Node\Expr $expr,
        MethodInfo $method,
        ProgramIndex $program,
        AnalysisContext $context,
        SensitiveRegistry $registry,
        TypeEnvironment $types,
        ?string $resourceModel,
        array $taints,
    ): array {
        $sources = [];

        $visit = function (Node|array|null $node) use (&$visit, &$sources, $context, $registry, $types, $resourceModel, $taints): void {
            if ($node === null) {
                return;
            }
            if (is_array($node)) {
                foreach ($node as $item) {
                    if ($item instanceof Node) {
                        $visit($item);
                    }
                }
                return;
            }

            if ($node instanceof Node\Expr && $this->isSanitized($node, $registry, $context)) {
                return; // Intentionally do not descend into sanitizer arguments.
            }

            if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                array_push($sources, ...($taints[$node->name] ?? []));
            }

            if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
                $field = $node->name->toString();
                $model = null;

                if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                    if ($node->var->name === 'this' && $resourceModel !== null) {
                        $model = $resourceModel;
                    } else {
                        $model = $types->typeOfVariableAt($node->var->name, max(1, $node->getStartLine()));
                    }
                }

                if ($node->var instanceof Node\Expr\PropertyFetch
                    && $node->var->var instanceof Node\Expr\Variable
                    && $node->var->var->name === 'this'
                    && $node->var->name instanceof Node\Identifier
                    && $node->var->name->toString() === 'resource') {
                    $model = $resourceModel;
                }

                $source = $registry->source($model, $field);
                if ($source !== null) {
                    $sources[] = $source;
                }
            }

            foreach ($node->getSubNodeNames() as $subName) {
                $child = $node->{$subName};
                if ($child instanceof Node || is_array($child)) {
                    $visit($child);
                }
            }
        };

        $visit($expr);
        return $sources;
    }

    private function isSanitized(Node\Expr $expr, SensitiveRegistry $registry, AnalysisContext $context): bool
    {
        foreach ($context->registry->sanitizerProviders() as $provider) {
            if ($provider->sanitizes($expr)) {
                return true;
            }
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            return $registry->isSanitizer(ltrim($expr->class->toString(), '\\').'::'.$expr->name->toString());
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $type = null;
            if ($expr->var instanceof Node\Expr\New_ && $expr->var->class instanceof Node\Name) {
                $type = $expr->var->class->toString();
            }
            return $type !== null && $registry->isSanitizer(ltrim($type, '\\').'::'.$expr->name->toString());
        }

        return false;
    }

    private function isJsonResponseCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && strcasecmp($node->name->toString(), 'json') === 0) {
            if ($node->var instanceof Node\Expr\FuncCall && $node->var->name instanceof Node\Name && strcasecmp($node->var->name->toString(), 'response') === 0) {
                return true;
            }
        }

        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && strcasecmp($node->name->toString(), 'json') === 0 && $node->class instanceof Node\Name) {
            $class = $node->class->toString();
            return str_ends_with($class, '\\Response') || strcasecmp($class, 'Response') === 0;
        }

        return false;
    }

    private function firstPayloadExpression(Node $node): ?Node\Expr
    {
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && isset($node->args[0]) && $node->args[0]->value instanceof Node\Expr) {
            return $node->args[0]->value;
        }
        return null;
    }

    /** @return list<string> */
    private function literalStringArguments(Node\Expr\MethodCall $call): array
    {
        $fields = [];
        foreach ($call->args as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Scalar\String_) {
                $fields[] = $value->value;
            } elseif ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item?->value instanceof Node\Scalar\String_) {
                        $fields[] = $item->value->value;
                    }
                }
            }
        }
        return $fields;
    }

    /** @param array<string, list<string>> $madeVisible */
    private function isDirectSerializedModelExpression(Node\Expr $expr, TypeEnvironment $types, ProgramIndex $program, array $madeVisible): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $model = $types->typeOfVariable($expr->name);
            return $model !== null && $program->isModelClass($model) && isset($madeVisible[$expr->name]);
        }

        return $expr instanceof Node\Expr\MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && $expr->name instanceof Node\Identifier
            && in_array(strtolower($expr->name->toString()), ['makevisible', 'setvisible'], true);
    }

    private function isAuthorizationConditional(Node\Expr $expr): bool
    {
        return NodeInspector::contains($expr, static fn (Node $node): bool =>
            $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && in_array(strtolower($node->name->toString()), ['when', 'whenloaded', 'whencount', 'whenaggregated'], true)
        );
    }

    private function severityForField(string $field): Severity
    {
        return in_array(strtolower($field), [
            'password', 'password_hash', 'secret', 'api_key', 'private_key',
            'access_token', 'refresh_token', 'two_factor_secret', 'two_factor_recovery_codes',
        ], true) ? Severity::CRITICAL : Severity::HIGH;
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
