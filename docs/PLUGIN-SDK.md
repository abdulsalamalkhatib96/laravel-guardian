# Plugin SDK

Guardian plugins register capabilities through `GuardianRegistry`.

```php
final class MyGuardianPlugin implements \Guardian\Contracts\Plugin
{
    public function register(\Guardian\Application\GuardianRegistry $registry): void
    {
        $registry->rule(new MyRule());
        $registry->effectProvider(new MyEffectProvider());
        $registry->sanitizer(new MySanitizer());
        $registry->concurrencyGuard(new MyLockProvider());
        $registry->reporter(new MyReporter());
    }
}
```

## Rule

A rule receives the shared `ProgramIndex` and `AnalysisContext` and yields structured `Finding` objects. Rules should use stable ids and semantic fingerprints.

## EffectProvider

Use this when a proprietary SDK performs an external irreversible effect that core cannot recognize by class name.

## SanitizerProvider

Use this when an application-specific redaction/masking primitive creates an approved output boundary for GUA-003.

## ConcurrencyGuardProvider

Use this for a project-specific lock abstraction. The provider should only return `true` for a construct that actually establishes mutual exclusion around the supplied callback.

## Reporter

Reporters must be deterministic format adapters and must not mutate findings.

## Rule quality bar

A new production rule should not be accepted without:

- concrete production failure mode;
- positive fixture;
- negative fixture;
- false-positive fixture;
- severity policy;
- confidence policy;
- remediation guidance;
- stable fingerprint strategy;
- baseline and suppression compatibility.
