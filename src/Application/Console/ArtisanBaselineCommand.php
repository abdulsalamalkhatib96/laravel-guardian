<?php

declare(strict_types=1);

namespace Guardian\Application\Console;

use Guardian\Application\Guardian;
use Guardian\Domain\Finding;
use Guardian\Suppression\Baseline;
use Illuminate\Console\Command;

final class ArtisanBaselineCommand extends Command
{
    protected $signature = 'guardian:baseline
        {--output= : Baseline output path}
        {--reason=accepted legacy finding : Reason stored for baseline entries}
        {--config= : Explicit Guardian config file}';

    protected $description = 'Generate or refresh the Guardian baseline from current findings';

    public function handle(Guardian $guardian): int
    {
        $base = base_path();
        $configFile = is_string($this->option('config')) && $this->option('config') !== '' ? $this->option('config') : null;
        $config = $guardian->configuration($base, [], $configFile);
        $path = is_string($this->option('output')) && $this->option('output') !== ''
            ? $this->option('output')
            : (string) $config->get('baseline', $base.'/guardian-baseline.json');
        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\/]/', $path)) {
            $path = $base.DIRECTORY_SEPARATOR.ltrim($path, '/\\');
        }

        $result = $guardian->scan($base, ['baseline' => null], $configFile);
        $findings = array_values(array_filter(
            $result->findings,
            static fn (Finding $finding): bool => ! ($finding->suppressed && str_starts_with((string) $finding->suppressionReason, 'inline:')),
        ));
        Baseline::write($path, $findings, (string) $this->option('reason'));
        $this->info(sprintf('Guardian baseline written to %s (%d findings).', $path, count($findings)));

        return self::SUCCESS;
    }
}
