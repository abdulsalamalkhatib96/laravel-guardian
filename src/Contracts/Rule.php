<?php

declare(strict_types=1);

namespace Guardian\Contracts;

use Guardian\Analysis\AnalysisContext;
use Guardian\Analysis\ProgramIndex;
use Guardian\Domain\Finding;
use Guardian\Domain\RuleId;
use Guardian\Domain\Severity;

interface Rule
{
    public function id(): RuleId;
    public function title(): string;
    public function defaultSeverity(): Severity;

    /** @return iterable<Finding> */
    public function analyse(ProgramIndex $program, AnalysisContext $context): iterable;
}
