<?php

declare(strict_types=1);

namespace Guardian\Tests\Unit;

use Guardian\Support\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testNestedConfigurationMergesWithoutDroppingSiblingRuleSettings(): void
    {
        $config = new Configuration([
            'rules' => [
                'GUA-001' => ['enabled' => true, 'queue_after_commit' => false],
            ],
        ]);

        $merged = $config->with(['rules' => ['GUA-001' => ['queue_after_commit' => true]]]);

        self::assertTrue($merged->ruleEnabled('GUA-001'));
        self::assertTrue($merged->get('rules.GUA-001.queue_after_commit'));
    }
}
