<?php

declare(strict_types=1);

namespace Guardian\Analysis\Support;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

final class AstPrinter
{
    private Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function expr(Node\Expr $expr): string
    {
        return $this->printer->prettyPrintExpr($expr);
    }
}
