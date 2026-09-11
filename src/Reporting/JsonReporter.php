<?php

declare(strict_types=1);

namespace Guardian\Reporting;

use Guardian\Contracts\Reporter;
use Guardian\Domain\ScanResult;

final class JsonReporter implements Reporter
{
    public function name(): string
    {
        return 'json';
    }

    public function render(ScanResult $result, string $basePath): string
    {
        return json_encode([
            'tool' => 'laravel-guardian',
            'metrics' => $result->metrics,
            'duration_seconds' => $result->durationSeconds,
            'counts' => $result->counts(),
            'findings' => array_map(static fn ($finding) => $finding->toArray($basePath), $result->findings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
