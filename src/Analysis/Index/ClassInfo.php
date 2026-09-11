<?php

declare(strict_types=1);

namespace Guardian\Analysis\Index;

final class ClassInfo
{
    /** @var array<string, MethodInfo> */
    public array $methods = [];

    /** @var array<string, string|null> */
    public array $properties = [];

    /** @var array<string, mixed> */
    public array $propertyDefaults = [];

    /** @var array<string, string> */
    public array $suppressions = [];

    /** @var array<string, string> lowercase field => reason */
    public array $sensitiveAllowances = [];

    public function __construct(
        public readonly string $name,
        public readonly ?string $extends,
        public readonly string $file,
        public readonly int $line,
    ) {}

    public function basename(): string
    {
        $parts = explode('\\', $this->name);
        return (string) end($parts);
    }

    public function isModel(): bool
    {
        return $this->extends === 'Illuminate\\Database\\Eloquent\\Model'
            || str_ends_with((string) $this->extends, '\\Model');
    }

    public function isJsonResource(): bool
    {
        return $this->extends === 'Illuminate\\Http\\Resources\\Json\\JsonResource'
            || str_ends_with((string) $this->extends, '\\JsonResource')
            || str_ends_with($this->basename(), 'Resource');
    }
}
