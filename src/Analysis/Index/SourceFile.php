<?php

declare(strict_types=1);

namespace Guardian\Analysis\Index;

use PhpParser\Node;

final readonly class SourceFile
{
    /** @param list<Node\Stmt> $statements */
    public function __construct(
        public string $path,
        public array $statements,
        public string $contents,
    ) {}
}
