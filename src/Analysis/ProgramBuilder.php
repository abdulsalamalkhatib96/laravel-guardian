<?php

declare(strict_types=1);

namespace Guardian\Analysis;

final readonly class ProgramBuilder
{
    public function __construct(
        private SourceFinder $finder,
        private PhpAstParser $parser,
        private ProgramIndexer $indexer,
    ) {}

    /** @param list<string> $paths @param list<string> $excludes */
    public function build(array $paths, array $excludes): ProgramIndex
    {
        $index = new ProgramIndex();
        foreach ($this->finder->find($paths, $excludes) as $file) {
            $source = $this->parser->parse($file);
            $this->indexer->addFile($index, $source);
        }

        return $index;
    }
}
