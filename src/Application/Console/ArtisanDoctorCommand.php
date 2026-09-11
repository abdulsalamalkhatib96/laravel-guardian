<?php

declare(strict_types=1);

namespace Guardian\Application\Console;

use Guardian\Application\Guardian;
use Illuminate\Console\Command;

final class ArtisanDoctorCommand extends Command
{
    protected $signature = 'guardian:doctor';
    protected $description = 'Validate Guardian dependencies, paths, and configuration';

    public function handle(Guardian $guardian): int
    {
        $config = $guardian->configuration(base_path());
        $checks = [
            ['PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['nikic/php-parser', class_exists(\PhpParser\ParserFactory::class)],
            ['PHPStan', class_exists(\PHPStan\Analyser\Scope::class)],
            ['Larastan', class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('larastan/larastan')],
        ];
        foreach ($config->paths() as $path) {
            $checks[] = ['scan path: '.$path, file_exists($path)];
        }

        $failed = false;
        foreach ($checks as [$label, $ok]) {
            $failed = $failed || ! $ok;
            $this->line(sprintf('[%s] %s', $ok ? 'OK' : 'FAIL', $label));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
