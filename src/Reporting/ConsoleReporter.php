<?php

declare(strict_types=1);

namespace Guardian\Reporting;

use Guardian\Contracts\Reporter;
use Guardian\Domain\Finding;
use Guardian\Domain\ScanResult;

final class ConsoleReporter implements Reporter
{
    public function name(): string
    {
        return 'console';
    }

    public function render(ScanResult $result, string $basePath): string
    {
        $counts = $result->counts();
        $lines = [
            'Laravel Guardian',
            str_repeat('─', 64),
            sprintf(
                'Scanned %d files · %d classes · %d methods · %.2fs',
                (int) ($result->metrics['files'] ?? 0),
                (int) ($result->metrics['classes'] ?? 0),
                (int) ($result->metrics['methods'] ?? 0),
                $result->durationSeconds,
            ),
            '',
            sprintf('CRITICAL  %-4d   HIGH  %-4d   MEDIUM  %-4d   LOW  %-4d', $counts['critical'], $counts['high'], $counts['medium'], $counts['low']),
            '',
        ];

        foreach ($result->activeFindings() as $finding) {
            array_push($lines, ...$this->findingLines($finding, $basePath));
        }

        $suppressed = count(array_filter($result->findings, static fn (Finding $f): bool => $f->suppressed));
        if ($result->activeFindings() === []) {
            $lines[] = 'No active Guardian findings.';
        }
        if ($suppressed > 0) {
            $lines[] = "Suppressed/baselined findings: {$suppressed}";
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /** @return list<string> */
    private function findingLines(Finding $finding, string $basePath): array
    {
        $path = $finding->location->relativeTo($basePath);
        $lines = [
            str_repeat('─', 64),
            sprintf('[%s] %s · %s confidence', (string) $finding->rule, strtoupper($finding->severity->value), strtoupper($finding->confidence->value)),
            $finding->title,
            sprintf('%s:%d', $path, $finding->location->line),
            '',
            $finding->message,
            '',
            'Risk:',
            $finding->risk,
        ];

        if ($finding->evidence !== []) {
            $lines[] = '';
            $lines[] = 'Evidence:';
            foreach ($finding->evidence as $item) {
                $lines[] = '  • '.$item;
            }
        }

        if ($finding->trace !== []) {
            $lines[] = '';
            $lines[] = 'Trace:';
            foreach ($finding->trace as $index => $step) {
                $lines[] = '  '.str_repeat('  ', $index).($index === 0 ? '' : '└─ ').$step;
            }
        }

        if ($finding->remediations !== []) {
            $lines[] = '';
            $lines[] = 'Recommended remediation:';
            foreach ($finding->remediations as $item) {
                $lines[] = '  • '.$item;
            }
        }

        $lines[] = 'Fingerprint: '.$finding->fingerprint->short();
        $lines[] = '';

        return $lines;
    }
}
