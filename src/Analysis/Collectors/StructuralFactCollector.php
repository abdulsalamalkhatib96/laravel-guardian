<?php

declare(strict_types=1);

namespace Guardian\Analysis\Collectors;

use Guardian\Analysis\Facts\CallFact;
use Guardian\Analysis\Facts\GuardianFact;
use Guardian\Analysis\Facts\PropertyReadFact;
use Guardian\Analysis\Facts\PropertyWriteFact;
use Guardian\Analysis\Facts\SerializationFact;
use Guardian\Analysis\Facts\TransactionFact;
use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\Support\NodeInspector;
use PhpParser\Node;

final class StructuralFactCollector
{
    /** @return list<GuardianFact> */
    public function collect(MethodInfo $method): array
    {
        $facts = [];
        NodeInspector::walk($method->node->stmts ?? [], function (Node $node) use (&$facts, $method): void {
            if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier) {
                $name = $node->name->toString();
                $facts[] = new CallFact($method->id(), $method->file, $node->getStartLine(), $name, $node instanceof Node\Expr\StaticCall ? 'static' : 'method');
                if (in_array(strtolower($name), ['transaction', 'begintransaction', 'commit', 'rollback'], true)) {
                    $facts[] = new TransactionFact($method->id(), $method->file, $node->getStartLine(), strtolower($name));
                }
                if (strcasecmp($name, 'json') === 0) {
                    $facts[] = new SerializationFact($method->id(), $method->file, $node->getStartLine(), 'json');
                }
            }

            if ($node instanceof Node\Expr\PropertyFetch
                && $node->var instanceof Node\Expr\Variable
                && is_string($node->var->name)
                && $node->name instanceof Node\Identifier) {
                $parent = $node->getAttribute('parent');
                $isWrite = ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp)
                    && $parent->var === $node;
                if ($isWrite) {
                    $facts[] = new PropertyWriteFact($method->id(), $method->file, $node->getStartLine(), $node->var->name, $node->name->toString(), $parent::class);
                } else {
                    $facts[] = new PropertyReadFact($method->id(), $method->file, $node->getStartLine(), $node->var->name, $node->name->toString());
                }
            }
        });

        return $facts;
    }
}
