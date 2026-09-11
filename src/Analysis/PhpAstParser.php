<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Analysis\Index\SourceFile;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

final class PhpAstParser
{
    public function parse(string $file): SourceFile
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read {$file}");
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $statements = $parser->parse($contents) ?? [];
        } catch (Error $error) {
            throw new \RuntimeException("Unable to parse {$file}: {$error->getMessage()}", 0, $error);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, [
            'replaceNodes' => true,
            'preserveOriginalNames' => true,
        ]));
        $traverser->addVisitor(new ParentConnectingVisitor());
        $statements = $traverser->traverse($statements);

        return new SourceFile($file, $statements, $contents);
    }
}
