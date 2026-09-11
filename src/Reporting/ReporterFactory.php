<?php

declare(strict_types=1);

namespace Guardian\Reporting;

use Guardian\Contracts\Reporter;

final class ReporterFactory
{
    public function make(string $format): Reporter
    {
        return match (strtolower($format)) {
            'console' => new ConsoleReporter(),
            'json' => new JsonReporter(),
            'sarif' => new SarifReporter(),
            'junit' => new JunitReporter(),
            'gitlab' => new GitlabReporter(),
            default => throw new \InvalidArgumentException("Unsupported Guardian format: {$format}"),
        };
    }
}
