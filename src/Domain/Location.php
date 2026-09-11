<?php

declare(strict_types=1);

namespace Guardian\Domain;

final readonly class Location
{
    public function __construct(
        public string $file,
        public int $line,
        public ?string $symbol = null,
    ) {}

    public function relativeTo(string $base): string
    {
        $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/').'/';
        $file = str_replace('\\', '/', realpath($this->file) ?: $this->file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
