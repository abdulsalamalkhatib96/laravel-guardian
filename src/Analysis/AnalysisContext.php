<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Application\GuardianRegistry;
use Guardian\Support\Configuration;
use Guardian\Suppression\Baseline;
use Guardian\Suppression\SuppressionMatcher;

final readonly class AnalysisContext
{
    public function __construct(
        public Configuration $config,
        public string $basePath,
        public Baseline $baseline,
        public SuppressionMatcher $suppressions,
        public GuardianRegistry $registry,
    ) {}
}
