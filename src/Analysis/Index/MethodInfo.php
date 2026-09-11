<?php

declare(strict_types=1);

namespace Guardian\Analysis\Index;

use PhpParser\Node\Stmt\ClassMethod;

final class MethodInfo
{
    /** @var array<string, string> */
    public array $parameterTypes = [];

    /** @var array<string, string> */
    public array $suppressions = [];

    /** @var array<string, string> lowercase field => reason */
    public array $sensitiveAllowances = [];

    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly string $file,
        public readonly int $line,
        public readonly ClassMethod $node,
    ) {}

    public function id(): string
    {
        return $this->class.'::'.$this->name;
    }
}
