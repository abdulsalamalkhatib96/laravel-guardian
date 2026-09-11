<?php

declare(strict_types=1);

namespace Guardian\Contracts;

use Guardian\Domain\ScanResult;

interface Reporter
{
    public function name(): string;
    public function render(ScanResult $result, string $basePath): string;
}
