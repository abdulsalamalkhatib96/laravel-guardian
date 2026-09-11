<?php

declare(strict_types=1);

namespace Guardian\Support;

final class ConfigurationLoader
{
    public function __construct(private readonly string $packageConfig) {}

    /** @param array<string, mixed> $overrides */
    public function load(string $basePath, ?string $explicitConfig = null, array $overrides = []): Configuration
    {
        $basePath = realpath($basePath) ?: rtrim($basePath, DIRECTORY_SEPARATOR);

        /** @var array<string, mixed> $defaults */
        $defaults = require $this->packageConfig;
        $projectConfig = $explicitConfig ?? $basePath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'guardian.php';

        if (is_file($projectConfig)) {
            /** @var mixed $loaded */
            $loaded = require $projectConfig;
            if (! is_array($loaded)) {
                throw new \InvalidArgumentException("Guardian configuration must return an array: {$projectConfig}");
            }
            /** @var array<string, mixed> $local */
            $local = $loaded;
            $defaults = Configuration::merge($defaults, $local);
        }

        $defaults = Configuration::merge($defaults, $overrides);

        return new Configuration($this->resolveProjectPaths($defaults, $basePath));
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function resolveProjectPaths(array $config, string $basePath): array
    {
        $config['paths'] = $this->resolveList((array) ($config['paths'] ?? []), $basePath);
        $config['exclude'] = $this->resolveList((array) ($config['exclude'] ?? []), $basePath);

        if (isset($config['baseline']) && is_string($config['baseline']) && $config['baseline'] !== '') {
            $config['baseline'] = $this->resolvePath($config['baseline'], $basePath);
        }

        return $config;
    }

    /** @param array<array-key, mixed> $paths @return list<string> */
    private function resolveList(array $paths, string $basePath): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $resolved[] = $this->resolvePath($path, $basePath);
        }
        return array_values(array_unique($resolved));
    }

    private function resolvePath(string $path, string $basePath): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
