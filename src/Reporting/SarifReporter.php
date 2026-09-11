<?php

declare(strict_types=1);

namespace Guardian\Reporting;

use Guardian\Contracts\Reporter;
use Guardian\Domain\Finding;
use Guardian\Domain\ScanResult;

final class SarifReporter implements Reporter
{
    public function name(): string
    {
        return 'sarif';
    }

    public function render(ScanResult $result, string $basePath): string
    {
        $rules = [];
        $items = [];
        foreach ($result->activeFindings() as $finding) {
            $ruleId = (string) $finding->rule;
            $rules[$ruleId] = [
                'id' => $ruleId,
                'name' => preg_replace('/[^A-Za-z0-9]+/', '', $finding->title),
                'shortDescription' => ['text' => $finding->title],
                'help' => ['text' => $finding->risk],
            ];
            $items[] = [
                'ruleId' => $ruleId,
                'level' => $this->level($finding),
                'message' => ['text' => $finding->message],
                'partialFingerprints' => ['guardian/v1' => (string) $finding->fingerprint],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => $finding->location->relativeTo($basePath)],
                        'region' => ['startLine' => $finding->location->line],
                    ],
                ]],
            ];
        }

        return json_encode([
            'version' => '2.1.0',
            '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
            'runs' => [[
                'tool' => ['driver' => ['name' => 'Laravel Guardian', 'rules' => array_values($rules)]],
                'results' => $items,
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    private function level(Finding $finding): string
    {
        return match ($finding->severity->value) {
            'critical', 'high' => 'error',
            'medium' => 'warning',
            default => 'note',
        };
    }
}
