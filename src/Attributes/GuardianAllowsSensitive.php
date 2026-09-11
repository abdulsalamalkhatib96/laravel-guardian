<?php

declare(strict_types=1);

namespace Guardian\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class GuardianAllowsSensitive
{
    /** @param list<string> $fields */
    public function __construct(
        public array $fields,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('GuardianAllowsSensitive requires a non-empty reason.');
        }
    }
}
