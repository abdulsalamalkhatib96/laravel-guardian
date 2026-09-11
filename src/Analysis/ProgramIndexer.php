<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Analysis\Collectors\StructuralFactCollector;
use Guardian\Analysis\Index\ClassInfo;
use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\Index\SourceFile;
use Guardian\Analysis\Support\AstValue;
use Guardian\Analysis\Support\NodeName;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final class ProgramIndexer
{
    public function addFile(ProgramIndex $index, SourceFile $source): void
    {
        $index->addFile($source);
        $this->walkStatements($index, $source, $source->statements);
    }

    /** @param list<Stmt> $statements */
    private function walkStatements(ProgramIndex $index, SourceFile $source, array $statements): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                $this->walkStatements($index, $source, $statement->stmts);
                continue;
            }

            if (! $statement instanceof Stmt\Class_) {
                continue;
            }

            $name = $statement->namespacedName?->toString() ?? $statement->name?->toString();
            if ($name === null) {
                continue;
            }

            $class = new ClassInfo(
                ltrim($name, '\\'),
                NodeName::of($statement->extends),
                $source->path,
                $statement->getStartLine(),
            );
            $class->suppressions = $this->extractSuppressions($statement->attrGroups);
            $class->sensitiveAllowances = $this->extractSensitiveAllowances($statement->attrGroups);

            foreach ($statement->getProperties() as $property) {
                $type = $this->typeName($property->type);
                foreach ($property->props as $prop) {
                    $propertyName = $prop->name->toString();
                    $class->properties[$propertyName] = $type;
                    if ($prop->default instanceof Node\Expr) {
                        $class->propertyDefaults[$propertyName] = AstValue::scalar($prop->default);
                    }
                }
            }

            foreach ($statement->getMethods() as $methodNode) {
                $method = new MethodInfo(
                    $class->name,
                    $methodNode->name->toString(),
                    $source->path,
                    $methodNode->getStartLine(),
                    $methodNode,
                );
                $method->suppressions = $this->extractSuppressions($methodNode->attrGroups);
                $method->sensitiveAllowances = $this->extractSensitiveAllowances($methodNode->attrGroups);

                foreach ($methodNode->params as $param) {
                    if (! $param->var instanceof Node\Expr\Variable || ! is_string($param->var->name)) {
                        continue;
                    }
                    $type = $this->typeName($param->type);
                    if ($type !== null) {
                        $method->parameterTypes[$param->var->name] = $type;
                    }

                    if ($param->flags !== 0 && $type !== null) {
                        $class->properties[$param->var->name] = $type;
                    }
                }

                $class->methods[strtolower($method->name)] = $method;
            }

            $index->addClass($class);
            $collector = new StructuralFactCollector();
            foreach ($class->methods as $method) {
                foreach ($collector->collect($method) as $fact) {
                    $index->addFact($fact);
                }
            }
        }
    }

    private function typeName(Node\Identifier|Node\Name|Node\ComplexType|null $type): ?string
    {
        if ($type instanceof Node\Name) {
            return ltrim($type->toString(), '\\');
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return $this->typeName($type->type);
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $inner) {
                $name = $this->typeName($inner);
                if ($name !== null && $name !== 'null') {
                    return $name;
                }
            }
        }

        return null;
    }

    /** @param list<Node\AttributeGroup> $groups @return array<string, string> */
    private function extractSensitiveAllowances(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = ltrim($attribute->name->toString(), '\\');
                if (! str_ends_with($name, 'GuardianAllowsSensitive')) {
                    continue;
                }

                $fields = [];
                $reason = null;
                foreach ($attribute->args as $position => $arg) {
                    $argName = $arg->name?->toString();
                    $value = AstValue::scalar($arg->value);
                    if ($argName === 'fields' || ($argName === null && $position === 0)) {
                        $fields = is_array($value) ? $value : [];
                    }
                    if ($argName === 'reason' || ($argName === null && $position === 1)) {
                        $reason = is_string($value) ? trim($value) : null;
                    }
                }

                if ($reason === null || $reason === '') {
                    continue;
                }
                foreach ($fields as $field) {
                    if (is_string($field) && trim($field) !== '') {
                        $result[strtolower(trim($field))] = $reason;
                    }
                }
            }
        }

        return $result;
    }

    /** @param list<Node\AttributeGroup> $groups @return array<string, string> */
    private function extractSuppressions(array $groups): array
    {
        $result = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = ltrim($attribute->name->toString(), '\\');
                if (! str_ends_with($name, 'GuardianIgnore')) {
                    continue;
                }
                $rule = null;
                $reason = null;
                foreach ($attribute->args as $position => $arg) {
                    $argName = $arg->name?->toString();
                    $value = $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
                    if ($argName === 'rule' || ($argName === null && $position === 0)) {
                        $rule = $value;
                    }
                    if ($argName === 'reason' || ($argName === null && $position === 1)) {
                        $reason = $value;
                    }
                }
                if (is_string($rule) && is_string($reason) && trim($reason) !== '') {
                    $result[$rule] = $reason;
                }
            }
        }

        return $result;
    }
}
