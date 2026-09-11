<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class ScanResult
{
    /**
     * @param list<Finding> $findings
     * @param array<string, int|float|string> $metrics
     */
    public function __construct(
        public array $findings,
        public array $metrics,
        public float $durationSeconds,
    ) {}

    /** @return list<Finding> */
    public function activeFindings(): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => ! $f->suppressed));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($this->activeFindings() as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }
}
