<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Domain\Confidence;
use Guardian\Domain\SensitiveSource;
use Guardian\Support\Configuration;

final class SensitiveRegistry
{
    /** @var array<string, array<string, string>> */
    private array $fields = [];

    /** @var list<string> */
    private array $builtins = [
        'password', 'password_hash', 'secret', 'api_key', 'private_key',
        'remember_token', 'access_token', 'refresh_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'client_secret',
    ];

    public function __construct(
        private readonly ProgramIndex $program,
        private readonly Configuration $config,
    ) {
        $this->boot();
    }

    private function boot(): void
    {
        foreach ($this->program->classes() as $class) {
            if (! $class->isModel() && ! $this->program->isModelClass($class->name)) {
                continue;
            }
            $hidden = $class->propertyDefaults['hidden'] ?? [];
            if (is_array($hidden)) {
                foreach ($hidden as $field) {
                    if (is_string($field)) {
                        $this->fields[strtolower($class->name)][strtolower($field)] = '$hidden';
                    }
                }
            }
        }

        foreach ((array) $this->config->get('rules.GUA-003.sensitive_fields', []) as $model => $fields) {
            if (! is_string($model) || ! is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (is_string($field)) {
                    $this->fields[strtolower(ltrim($model, '\\'))][strtolower($field)] = 'configuration';
                }
            }
        }
    }

    public function source(?string $model, string $field): ?SensitiveSource
    {
        $fieldKey = strtolower($field);
        if ($model !== null) {
            $modelKey = strtolower(ltrim($model, '\\'));
            if (isset($this->fields[$modelKey][$fieldKey])) {
                return new SensitiveSource(
                    ltrim($model, '\\'),
                    $field,
                    Confidence::HIGH,
                    $this->fields[$modelKey][$fieldKey],
                );
            }
        }

        if (in_array($fieldKey, $this->builtins, true)) {
            return new SensitiveSource($model ?? 'unknown-model', $field, Confidence::MEDIUM, 'known-sensitive-name');
        }

        return null;
    }

    /** @return list<string> */
    public function fieldsForModel(string $model): array
    {
        return array_keys($this->fields[strtolower(ltrim($model, '\\'))] ?? []);
    }

    public function resourceModel(string $resource): ?string
    {
        $configured = (array) $this->config->get('rules.GUA-003.resource_models', []);
        foreach ($configured as $resourceClass => $model) {
            if (is_string($resourceClass) && is_string($model) && strcasecmp(ltrim($resourceClass, '\\'), ltrim($resource, '\\')) === 0) {
                return ltrim($model, '\\');
            }
        }

        $class = $this->program->class($resource);
        if ($class === null) {
            return null;
        }
        $base = preg_replace('/Resource$/', '', $class->basename()) ?: $class->basename();
        $candidate = $this->program->findClassByBasename($base);
        if ($candidate === null || ! $this->program->isModelClass($candidate->name)) {
            return null;
        }

        return $candidate->name;
    }

    public function isSanitizer(string $callable): bool
    {
        foreach ((array) $this->config->get('rules.GUA-003.sanitizers', []) as $sanitizer) {
            if (is_string($sanitizer) && strcasecmp(ltrim($sanitizer, '\\'), ltrim($callable, '\\')) === 0) {
                return true;
            }
        }
        return false;
    }
}
