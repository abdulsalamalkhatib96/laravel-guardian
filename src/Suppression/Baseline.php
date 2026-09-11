<?php

declare(strict_types=1);

namespace Guardian\Suppression;

use Guardian\Domain\Finding;

final class Baseline
{
    /** @var array<string, array{rule:string,reason:string,first_seen:?string}> */
    private array $entries = [];

    /** @param array<string, array{rule:string,reason:string,first_seen:?string}> $entries */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public static function load(?string $path): self
    {
        if ($path === null || ! is_file($path)) {
            return new self();
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return new self();
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self();
        }

        $entries = [];
        foreach ((array) ($data['findings'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['fingerprint'] ?? null)) {
                continue;
            }
            $entries[$item['fingerprint']] = [
                'rule' => is_string($item['rule'] ?? null) ? $item['rule'] : '',
                'reason' => is_string($item['reason'] ?? null) ? $item['reason'] : 'baseline',
                'first_seen' => is_string($item['first_seen'] ?? null) ? $item['first_seen'] : null,
            ];
        }

        return new self($entries);
    }

    public function contains(Finding $finding): bool
    {
        return isset($this->entries[(string) $finding->fingerprint]);
    }

    public function reason(Finding $finding): ?string
    {
        return $this->entries[(string) $finding->fingerprint]['reason'] ?? null;
    }

    /** @return array<string, array{rule:string,reason:string,first_seen:?string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @param list<Finding> $findings */
    public static function write(string $path, array $findings, string $reason = 'accepted legacy finding'): void
    {
        $items = [];
        foreach ($findings as $finding) {
            $items[] = [
                'fingerprint' => (string) $finding->fingerprint,
                'rule' => (string) $finding->rule,
                'reason' => $reason,
                'first_seen' => date('Y-m-d'),
            ];
        }

        usort($items, static fn (array $a, array $b): int => [$a['rule'], $a['fingerprint']] <=> [$b['rule'], $b['fingerprint']]);
        $payload = json_encode(['version' => 1, 'findings' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create baseline directory {$directory}");
        }
        if (file_put_contents($path, $payload) === false) {
            throw new \RuntimeException("Unable to write Guardian baseline to {$path}");
        }
    }
}
