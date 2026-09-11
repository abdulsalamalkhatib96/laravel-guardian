<?php

declare(strict_types=1);

namespace Guardian\Suppression;

use Guardian\Analysis\ProgramIndex;
use Guardian\Domain\Finding;

final class SuppressionMatcher
{
    /** @var array<string, list<string>> */
    private array $lines = [];

    public function reasonFor(Finding $finding, ProgramIndex $program): ?string
    {
        $symbol = $finding->location->symbol;
        if ($symbol !== null && str_contains($symbol, '::')) {
            [$className, $methodName] = explode('::', $symbol, 2);
            $method = $program->method($className, $methodName);
            if ($method !== null && isset($method->suppressions[(string) $finding->rule])) {
                return 'attribute: '.$method->suppressions[(string) $finding->rule];
            }
            $class = $program->class($className);
            if ($class !== null && isset($class->suppressions[(string) $finding->rule])) {
                return 'attribute: '.$class->suppressions[(string) $finding->rule];
            }

            if ((string) $finding->rule === 'GUA-003') {
                $field = $this->sensitiveField($finding);
                if ($field !== null && $method !== null && isset($method->sensitiveAllowances[$field])) {
                    return 'attribute: '.$method->sensitiveAllowances[$field];
                }
                if ($field !== null && $class !== null && isset($class->sensitiveAllowances[$field])) {
                    return 'attribute: '.$class->sensitiveAllowances[$field];
                }
            }
        }

        return $this->inlineReason($finding->location->file, $finding->location->line, (string) $finding->rule);
    }

    private function sensitiveField(Finding $finding): ?string
    {
        foreach ($finding->tags as $tag) {
            if (str_starts_with($tag, 'sensitive-field:')) {
                $field = strtolower(substr($tag, strlen('sensitive-field:')));
                return $field !== '' ? $field : null;
            }
        }

        return null;
    }

    private function inlineReason(string $file, int $line, string $rule): ?string
    {
        if (! isset($this->lines[$file])) {
            $contents = @file($file, FILE_IGNORE_NEW_LINES);
            $this->lines[$file] = is_array($contents) ? array_values($contents) : [];
        }

        $lines = $this->lines[$file];
        foreach ([$line, $line - 1, $line - 2] as $candidateLine) {
            if ($candidateLine < 1 || ! isset($lines[$candidateLine - 1])) {
                continue;
            }
            $text = $lines[$candidateLine - 1];
            if (preg_match('/guardian-ignore\s+'.preg_quote($rule, '/').'\s*:\s*(.+)$/i', $text, $matches) === 1) {
                $reason = trim($matches[1]);
                if ($reason !== '') {
                    return 'inline: '.$reason;
                }
            }
        }

        return null;
    }
}
