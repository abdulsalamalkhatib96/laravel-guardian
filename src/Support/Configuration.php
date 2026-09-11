<?php

declare(strict_types=1);

namespace Guardian\Support;

final readonly class Configuration
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function ruleEnabled(string $rule): bool
    {
        return (bool) $this->get("rules.{$rule}.enabled", true);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_values(array_filter((array) $this->get('paths', []), 'is_string'));
    }

    /** @return list<string> */
    public function excludes(): array
    {
        return array_values(array_filter((array) $this->get('exclude', []), 'is_string'));
    }

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(self::merge($this->values, $overrides));
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $override @return array<string, mixed> */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
