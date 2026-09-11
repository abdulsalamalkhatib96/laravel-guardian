<?php

declare(strict_types=1);

namespace Guardian\Application;

use Guardian\Contracts\ConcurrencyGuardProvider;
use Guardian\Contracts\EffectProvider;
use Guardian\Contracts\Plugin;
use Guardian\Contracts\Reporter;
use Guardian\Contracts\Rule;
use Guardian\Contracts\SanitizerProvider;

final class GuardianRegistry
{
    /** @var array<string, Rule> */
    private array $rules = [];

    /** @var array<string, Reporter> */
    private array $reporters = [];

    /** @var list<EffectProvider> */
    private array $effectProviders = [];

    /** @var list<SanitizerProvider> */
    private array $sanitizerProviders = [];

    /** @var list<ConcurrencyGuardProvider> */
    private array $concurrencyGuards = [];

    public function rule(Rule $rule): self
    {
        $this->rules[(string) $rule->id()] = $rule;
        return $this;
    }

    public function reporter(Reporter $reporter): self
    {
        $this->reporters[$reporter->name()] = $reporter;
        return $this;
    }

    public function effectProvider(EffectProvider $provider): self
    {
        $this->effectProviders[] = $provider;
        return $this;
    }

    public function sanitizer(SanitizerProvider $provider): self
    {
        $this->sanitizerProviders[] = $provider;
        return $this;
    }

    public function concurrencyGuard(ConcurrencyGuardProvider $provider): self
    {
        $this->concurrencyGuards[] = $provider;
        return $this;
    }

    public function plugin(Plugin $plugin): self
    {
        $plugin->register($this);
        return $this;
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return array_values($this->rules);
    }

    public function findRule(string $id): ?Rule
    {
        return $this->rules[$id] ?? null;
    }

    public function findReporter(string $name): ?Reporter
    {
        return $this->reporters[$name] ?? null;
    }

    /** @return list<EffectProvider> */
    public function effectProviders(): array
    {
        return $this->effectProviders;
    }

    /** @return list<SanitizerProvider> */
    public function sanitizerProviders(): array
    {
        return $this->sanitizerProviders;
    }

    /** @return list<ConcurrencyGuardProvider> */
    public function concurrencyGuards(): array
    {
        return $this->concurrencyGuards;
    }
}
