<?php

declare(strict_types=1);

namespace Guardian\Tests\Unit;

use Guardian\Domain\Fingerprint;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    public function testFingerprintIsOrderIndependentAndNormalized(): void
    {
        $a = Fingerprint::make(['rule' => 'GUA-001', 'symbol' => ' App\\Service::Run ']);
        $b = Fingerprint::make(['symbol' => 'app\\service::run', 'rule' => 'gua-001']);

        self::assertSame((string) $a, (string) $b);
        self::assertSame(12, strlen($a->short()));
    }
}
