<?php

declare(strict_types=1);

namespace Guardian\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class GuardianIgnore
{
    public function __construct(
        public string $rule,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('GuardianIgnore requires a non-empty reason.');
        }
    }
}
