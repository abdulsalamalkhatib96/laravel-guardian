<?php

declare(strict_types=1);

namespace Guardian\Tests\Unit;

use Guardian\Domain\Confidence;
use Guardian\Domain\Finding;
use Guardian\Domain\Fingerprint;
use Guardian\Domain\Location;
use Guardian\Domain\RuleId;
use Guardian\Domain\Severity;
use Guardian\Suppression\Baseline;
use PHPUnit\Framework\TestCase;

final class BaselineTest extends TestCase
{
    public function testBaselineRoundTrip(): void
    {
        $path = sys_get_temp_dir().'/guardian-baseline-'.bin2hex(random_bytes(4)).'.json';
        $finding = new Finding(
            new RuleId('GUA-001'),
            Severity::CRITICAL,
            Confidence::HIGH,
            new Location(__FILE__, __LINE__, __METHOD__),
            'title', 'message', 'risk', [], [], [],
            Fingerprint::make(['rule' => 'GUA-001', 'symbol' => __METHOD__]),
        );

        Baseline::write($path, [$finding]);
        $loaded = Baseline::load($path);

        self::assertTrue($loaded->contains($finding));
        @unlink($path);
    }
}
