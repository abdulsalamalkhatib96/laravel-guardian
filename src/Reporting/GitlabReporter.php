<?php

declare(strict_types=1);

namespace Guardian\Reporting;

use Guardian\Contracts\Reporter;
use Guardian\Domain\ScanResult;

final class GitlabReporter implements Reporter
{
    public function name(): string
    {
        return 'gitlab';
    }

    public function render(ScanResult $result, string $basePath): string
    {
        $items = [];
        foreach ($result->activeFindings() as $finding) {
            $items[] = [
                'description' => '['.(string) $finding->rule.'] '.$finding->message,
                'check_name' => (string) $finding->rule,
                'fingerprint' => (string) $finding->fingerprint,
                'severity' => match ($finding->severity->value) {
                    'critical' => 'critical',
                    'high' => 'major',
                    'medium' => 'minor',
                    'low' => 'info',
                    default => 'info',
                },
                'location' => [
                    'path' => $finding->location->relativeTo($basePath),
                    'lines' => ['begin' => $finding->location->line],
                ],
            ];
        }

        return json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
