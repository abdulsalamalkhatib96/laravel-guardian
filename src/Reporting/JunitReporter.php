<?php

declare(strict_types=1);

namespace Guardian\Reporting;

use Guardian\Contracts\Reporter;
use Guardian\Domain\ScanResult;

final class JunitReporter implements Reporter
{
    public function name(): string
    {
        return 'junit';
    }

    public function render(ScanResult $result, string $basePath): string
    {
        $active = $result->activeFindings();
        $tests = max(1, count($active));
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf(
                '<testsuite name="Laravel Guardian" tests="%d" failures="%d" time="%s">',
                $tests,
                count($active),
                $this->xml(number_format($result->durationSeconds, 3, '.', '')),
            ),
        ];

        if ($active === []) {
            $lines[] = '  <testcase name="guardian-scan" classname="LaravelGuardian" />';
        }

        foreach ($active as $finding) {
            $message = $finding->message."\n".$finding->location->relativeTo($basePath).':'.$finding->location->line;
            $lines[] = sprintf(
                '  <testcase name="%s" classname="LaravelGuardian">',
                $this->xml((string) $finding->rule),
            );
            $lines[] = sprintf(
                '    <failure type="%s" message="%s">%s</failure>',
                $this->xml($finding->severity->value),
                $this->xml($finding->title),
                $this->xml($message),
            );
            $lines[] = '  </testcase>';
        }

        $lines[] = '</testsuite>';
        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
