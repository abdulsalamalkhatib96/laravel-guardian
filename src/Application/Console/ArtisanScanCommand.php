<?php

declare(strict_types=1);

namespace Guardian\Application\Console;

use Guardian\Application\Guardian;
use Illuminate\Console\Command;

final class ArtisanScanCommand extends Command
{
    protected $signature = 'guardian:scan
        {--path=* : Paths to scan relative to the project root}
        {--format=console : console|json|sarif|junit|gitlab}
        {--fail-on= : critical|high|medium|low}
        {--min-confidence= : high|medium|low}
        {--baseline= : Baseline file path}
        {--config= : Explicit Guardian config file}';

    protected $description = 'Scan the Laravel application for production correctness violations';

    public function handle(Guardian $guardian): int
    {
        $base = base_path();
        $overrides = [];
        $paths = array_values(array_filter((array) $this->option('path'), 'is_string'));
        if ($paths !== []) {
            $overrides['paths'] = $paths;
        }
        foreach ([
            'fail-on' => 'fail_on',
            'min-confidence' => 'minimum_confidence',
            'baseline' => 'baseline',
        ] as $option => $key) {
            $value = $this->option($option);
            if (is_string($value) && $value !== '') {
                $overrides[$key] = $value;
            }
        }

        $configFile = is_string($this->option('config')) && $this->option('config') !== '' ? $this->option('config') : null;
        $result = $guardian->scan($base, $overrides, $configFile);
        $this->output->write($guardian->render($result, (string) $this->option('format'), $base));

        return $guardian->exitCode($result, $guardian->configuration($base, $overrides, $configFile));
    }
}
