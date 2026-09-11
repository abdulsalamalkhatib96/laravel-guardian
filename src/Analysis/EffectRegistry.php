<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Analysis\Index\MethodInfo;
use Guardian\Contracts\EffectProvider;
use Guardian\Analysis\Support\NodeName;
use Guardian\Analysis\Support\TypeEnvironment;
use Guardian\Domain\Confidence;
use Guardian\Domain\Effect;
use Guardian\Domain\Severity;
use Guardian\Support\Configuration;
use PhpParser\Node;

final readonly class EffectRegistry
{
    /** @param list<EffectProvider> $providers */
    public function __construct(private Configuration $config, private array $providers = []) {}

    public function detect(Node\Expr $expr, MethodInfo $method, TypeEnvironment $types): ?Effect
    {
        foreach ($this->providers as $provider) {
            $effect = $provider->detect($expr, $method);
            if ($effect !== null) {
                return $effect;
            }
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
            $class = NodeName::of($expr->class);
            $name = $expr->name->toString();
            if ($class !== null) {
                if ($this->isClass($class, ['Http', 'Illuminate\\Support\\Facades\\Http'])
                    && $this->isHttpTerminal($name)) {
                    return new Effect('HTTP', $class.'::'.$name, Severity::CRITICAL);
                }
                if ($this->isClass($class, ['Mail', 'Illuminate\\Support\\Facades\\Mail'])
                    && $this->isMailTerminal($name)) {
                    return new Effect('MAIL', $class.'::'.$name, Severity::HIGH);
                }
                if ($this->isClass($class, ['Notification', 'Illuminate\\Support\\Facades\\Notification'])
                    && in_array(strtolower($name), ['send', 'sendnow'], true)) {
                    return new Effect('NOTIFICATION', $class.'::'.$name, Severity::HIGH);
                }
                if ($this->isClass($class, ['Storage', 'Illuminate\\Support\\Facades\\Storage'])
                    && in_array(strtolower($name), ['put', 'write', 'delete', 'move', 'copy', 'prepend', 'append'], true)) {
                    return new Effect('FILESYSTEM_WRITE', $class.'::'.$name, Severity::HIGH);
                }
                if ($this->isClass($class, ['Process', 'Illuminate\\Support\\Facades\\Process'])
                    && in_array(strtolower($name), ['run', 'start', 'pipe'], true)) {
                    return new Effect('PROCESS', $class.'::'.$name, Severity::CRITICAL);
                }
                if (in_array(strtolower($name), ['dispatch', 'dispatchif', 'dispatchunless', 'dispatchsync', 'dispatchafterresponse'], true)) {
                    $deferred = $this->isAfterCommitChain($expr)
                        || $this->config->get('rules.GUA-001.queue_after_commit') === true;
                    return new Effect('QUEUE', $class.'::'.$name, Severity::HIGH, Confidence::HIGH, $deferred);
                }
                if ($this->customEffect($class, $name)) {
                    return new Effect('EXTERNAL_SDK', $class.'::'.$name, Severity::CRITICAL);
                }
            }
        }

        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            $name = strtolower($expr->name->toString());
            if (in_array($name, ['dispatch'], true)) {
                return new Effect(
                    'QUEUE',
                    $name.'()',
                    Severity::HIGH,
                    Confidence::HIGH,
                    $this->isAfterCommitChain($expr) || $this->config->get('rules.GUA-001.queue_after_commit') === true,
                );
            }
        }

        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $name = $expr->name->toString();
            $type = $types->typeOfExpr($expr->var);
            $lower = strtolower($name);

            if ($type !== null
                && ($this->isClass($type, ['Http', 'Illuminate\\Support\\Facades\\Http'])
                    || str_contains($type, 'GuzzleHttp\\Client')
                    || str_contains($type, 'PendingRequest'))
                && $this->isHttpTerminal($name)) {
                return new Effect('HTTP', $type.'->'.$name, Severity::CRITICAL);
            }

            if ($type !== null
                && $this->isClass($type, ['Mail', 'Illuminate\\Support\\Facades\\Mail'])
                && $this->isMailTerminal($name)) {
                return new Effect('MAIL', $type.'->'.$name, Severity::HIGH);
            }

            if ($type !== null
                && $this->isClass($type, ['Notification', 'Illuminate\\Support\\Facades\\Notification'])
                && in_array($lower, ['send', 'sendnow', 'notify'], true)) {
                return new Effect('NOTIFICATION', $type.'->'.$name, Severity::HIGH);
            }

            if ($type !== null && $this->customEffect($type, $name)) {
                return new Effect('EXTERNAL_SDK', $type.'->'.$name, Severity::CRITICAL, Confidence::HIGH);
            }
        }

        return null;
    }

    private function isAfterCommitChain(Node\Expr $expr): bool
    {
        $parent = $expr->getAttribute('parent');
        return $parent instanceof Node\Expr\MethodCall
            && $parent->name instanceof Node\Identifier
            && strcasecmp($parent->name->toString(), 'afterCommit') === 0;
    }

    private function isHttpTerminal(string $method): bool
    {
        return in_array(strtolower($method), [
            'get', 'post', 'put', 'patch', 'delete', 'head', 'send', 'request', 'pool', 'batch',
        ], true);
    }

    private function isMailTerminal(string $method): bool
    {
        return in_array(strtolower($method), [
            'send', 'raw', 'html', 'text', 'queue', 'later',
        ], true);
    }

    /** @param list<string> $candidates */
    private function isClass(string $class, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (strcasecmp($class, $candidate) === 0 || str_ends_with($class, '\\'.$candidate)) {
                return true;
            }
        }
        return false;
    }

    private function customEffect(string $class, string $method): bool
    {
        $needle = strtolower(ltrim($class, '\\').'::'.$method);
        foreach ((array) $this->config->get('rules.GUA-001.custom_effects', []) as $effect) {
            if (is_string($effect) && strtolower(ltrim($effect, '\\')) === $needle) {
                return true;
            }
        }
        return false;
    }
}
