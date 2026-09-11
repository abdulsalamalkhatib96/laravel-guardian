<?php

declare(strict_types=1);

namespace Guardian\Tests\Unit;

use Guardian\Support\ConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class ConfigurationLoaderTest extends TestCase
{
    public function testRelativePathsAreResolvedAgainstScannedProjectRoot(): void
    {
        $root = sys_get_temp_dir().'/guardian-config-'.bin2hex(random_bytes(4));
        mkdir($root.'/app', 0777, true);

        $loader = new ConfigurationLoader(dirname(__DIR__, 2).'/config/guardian.php');
        $config = $loader->load($root);

        self::assertSame([$root.'/app'], $config->paths());
        self::assertSame($root.'/guardian-baseline.json', $config->get('baseline'));
        self::assertContains($root.'/vendor', $config->excludes());
    }

    public function testRelativeOverridePathsAreAlsoResolvedAgainstScannedProjectRoot(): void
    {
        $root = sys_get_temp_dir().'/guardian-config-'.bin2hex(random_bytes(4));
        mkdir($root.'/domain', 0777, true);

        $loader = new ConfigurationLoader(dirname(__DIR__, 2).'/config/guardian.php');
        $config = $loader->load($root, null, [
            'paths' => ['domain'],
            'baseline' => 'var/guardian.json',
        ]);

        self::assertSame([$root.'/domain'], $config->paths());
        self::assertSame($root.'/var/guardian.json', $config->get('baseline'));
    }
}
