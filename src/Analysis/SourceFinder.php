<?php

declare(strict_types=1);

namespace Guardian\Analysis;

final class SourceFinder
{
    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @return list<string>
     */
    public function find(array $paths, array $excludes = []): array
    {
        $normalizedExcludes = array_map([$this, 'normalize'], $excludes);
        $files = [];

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            if (is_file($path) && str_ends_with(strtolower($path), '.php')) {
                if (! $this->excluded($path, $normalizedExcludes)) {
                    $files[] = realpath($path) ?: $path;
                }
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $filename = $file->getPathname();
                if (! $this->excluded($filename, $normalizedExcludes)) {
                    $files[] = realpath($filename) ?: $filename;
                }
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    /** @param list<string> $excludes */
    private function excluded(string $file, array $excludes): bool
    {
        $file = $this->normalize($file);
        foreach ($excludes as $exclude) {
            if ($exclude !== '' && str_starts_with($file, rtrim($exclude, '/').'/')) {
                return true;
            }
            if ($file === rtrim($exclude, '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', realpath($path) ?: $path);
    }
}
