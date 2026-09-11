<?php

declare(strict_types=1);

namespace Guardian\Tests\Integration;

use Guardian\Application\Guardian;
use PHPUnit\Framework\TestCase;

final class GuardianScanTest extends TestCase
{
    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardian = Guardian::createDefault();
    }

    public function testGua001FindsInterproceduralHttpInsideTransaction(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA001/unsafe.php', ['GUA-001']);
        self::assertContains('GUA-001', array_map(static fn ($f) => (string) $f->rule, $result->activeFindings()));
    }

    public function testGua001DoesNotTreatHttpRequestConfigurationAsASideEffect(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA001/safe-http-configuration.php', ['GUA-001']);
        self::assertSame([], $result->activeFindings());
    }

    public function testGua001FindsFluentHttpTerminalCallInsideTransaction(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA001/unsafe-fluent-http.php', ['GUA-001']);
        self::assertNotEmpty($result->activeFindings());
    }

    public function testGua001AllowsAfterCommitDispatch(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA001/safe-after-commit.php', ['GUA-001']);
        self::assertSame([], $result->activeFindings());
    }

    public function testGua002FindsClassicLostUpdate(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA002/unsafe.php', ['GUA-002']);
        self::assertNotEmpty($result->activeFindings());
    }

    public function testGua002AllowsPessimisticLockInsideTransaction(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA002/safe-lock.php', ['GUA-002']);
        self::assertSame([], $result->activeFindings());
    }

    public function testGua002AllowsPessimisticLockInsideReturnedTransaction(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA002/safe-return-lock.php', ['GUA-002']);
        self::assertSame([], $result->activeFindings());
    }

    public function testGua002AllowsFixedAtomicIncrement(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA002/safe-atomic.php', ['GUA-002']);
        self::assertSame([], $result->activeFindings());
    }

    public function testGua002RejectsAtomicIncrementWhoseDeltaComesFromStaleBalance(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA002/unsafe-derived-atomic.php', ['GUA-002']);
        self::assertNotEmpty($result->activeFindings());
    }

    public function testGua003FindsHiddenAttributeReturnedByResource(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA003/unsafe.php', ['GUA-003']);
        self::assertNotEmpty($result->activeFindings());
    }

    public function testGua003AllowsNarrowDocumentedSensitiveExposureAttribute(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA003/safe-allowed.php', ['GUA-003']);
        self::assertSame([], $result->activeFindings());
        self::assertNotEmpty(array_filter($result->findings, static fn ($finding) => $finding->suppressed));
    }

    public function testGua003AllowsConfiguredMaskSanitizer(): void
    {
        $result = $this->scan(__DIR__.'/../Fixtures/GUA003/safe-masked.php', ['GUA-003']);
        self::assertSame([], $result->activeFindings());
    }

    private function scan(string $path, array $enabledRules): \Guardian\Domain\ScanResult
    {
        $rules = [];
        foreach (['GUA-001', 'GUA-002', 'GUA-003'] as $rule) {
            $rules[$rule] = ['enabled' => in_array($rule, $enabledRules, true)];
        }

        return $this->guardian->scan(dirname(__DIR__, 2), [
            'paths' => [$path],
            'exclude' => [],
            'baseline' => null,
            'rules' => $rules,
        ]);
    }
}
