<?php

declare(strict_types=1);

namespace Guardian\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * A tiny collector whose only job is to make PHPStan emit CollectedDataNode.
 * Guardian's own whole-program engine then runs once from GuardianCollectedDataRule.
 *
 * @implements Collector<Class_, array{file:string}>
 */
final class GuardianTriggerCollector implements Collector
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /** @return array{file:string}|null */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $node instanceof Class_) {
            return null;
        }

        return ['file' => $scope->getFile()];
    }
}
