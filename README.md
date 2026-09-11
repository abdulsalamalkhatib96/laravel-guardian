# Laravel Guardian

**Production correctness engine for Laravel.**

Laravel Guardian looks for code that is syntactically valid, type-correct, and often testable, but can still violate production invariants under transactions, concurrency, or serialization.

V1 intentionally ships only three rules:

- **GUA-001** — External side effects inside database transactions.
- **GUA-002** — Dangerous Eloquent read-modify-write / check-then-act flows.
- **GUA-003** — Sensitive data reaching API serialization paths.

Guardian is not a replacement for PHPStan, Larastan, Telescope, Debugbar, or a generic SAST scanner. It focuses on Laravel-specific production semantics.

## Requirements

- PHP 8.2+
- Laravel 11.44.2+, 12.4.1+, or 13.x
- PHPStan 2.2.2+
- Larastan 3.11+

## Installation

After publishing the package to Packagist:

```bash
composer require --dev abdulsalamalkhatib/laravel-guardian
php artisan vendor:publish --tag=guardian-config
```

For local development from this ZIP before publishing:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-guardian"
    }
  ]
}
```

Then:

```bash
composer require --dev abdulsalamalkhatib/laravel-guardian:@dev
```

## Quick start

```bash
php artisan guardian:scan
```

or without booting the Laravel application:

```bash
vendor/bin/guardian scan
```

Example output:

```text
Laravel Guardian
────────────────────────────────────────────────────────────────
Scanned 423 files · 184 classes · 3,192 methods · 1.28s

CRITICAL  2      HIGH  7      MEDIUM  3      LOW  0

[GUA-001] CRITICAL · HIGH confidence
External side effect inside database transaction
app/Services/PaymentService.php:91

Risk:
The external operation may succeed irreversibly while the database
transaction later rolls back, leaving systems inconsistent.
```

## GUA-001 — transaction side effects

Unsafe:

```php
DB::transaction(function () use ($payment) {
    $payment->update(['status' => 'paid']);

    Http::post($gatewayUrl, [
        'payment_id' => $payment->id,
    ]);
});
```

The remote system may commit while the DB transaction later rolls back.

Guardian also follows known application calls:

```php
DB::transaction(function () {
    $this->paymentService->charge();
});

// ...

public function charge(): void
{
    $this->gateway->capture();
}

public function capture(): void
{
    Http::post(...);
}
```

Safe queued pattern:

```php
DB::transaction(function () use ($payment) {
    $payment->update(['status' => 'paid']);

    SendPaymentWebhook::dispatch($payment->id)->afterCommit();
});
```

Built-in external-effect families include HTTP, mail, notifications, queue dispatch, filesystem writes, process execution, and configured external SDK methods.

## GUA-002 — unsafe Eloquent read-modify-write

Unsafe lost update:

```php
$wallet = Wallet::findOrFail($walletId);
$wallet->balance += $amount;
$wallet->save();
```

A database transaction by itself does **not** make this safe.

Safe pessimistic lock:

```php
DB::transaction(function () use ($walletId, $amount) {
    $wallet = Wallet::query()
        ->whereKey($walletId)
        ->lockForUpdate()
        ->firstOrFail();

    $wallet->balance += $amount;
    $wallet->save();
});
```

Safe fixed atomic delta:

```php
Wallet::whereKey($walletId)->increment('balance', $amount);
```

Still unsafe despite using `increment()`:

```php
$wallet = Wallet::findOrFail($walletId);
$delta = $targetBalance - $wallet->balance;
$wallet->increment('balance', $delta);
```

The write primitive is atomic, but the **delta was derived from stale state**. Guardian distinguishes this from a fixed atomic increment.

Guardian also detects check-then-act transitions such as:

```php
if ($withdrawal->status === 'pending') {
    $withdrawal->status = 'approved';
    $withdrawal->save();
}
```

Use row locks or compare-and-swap semantics for state transitions.

## GUA-003 — sensitive exposure

Guardian builds a sensitivity registry from:

1. explicit `sensitive_fields` configuration;
2. Eloquent `$hidden` fields;
3. a conservative built-in list for secrets such as passwords, API keys, refresh tokens, and 2FA secrets.

Unsafe:

```php
final class PlayerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
        ];
    }
}
```

If `Player::$hidden` or Guardian config marks `phone` as sensitive, the resource is reported.

Configured sanitizers are treated as declassification boundaries:

```php
return [
    'phone' => Str::mask($this->phone, '*', 3),
];
```

Guardian also tracks simple local-variable taint into `response()->json()`, `JsonResponse`, and resource arrays.

## Configuration

Publish `config/guardian.php` and tune only application-specific policy:

```php
return [
    // Relative to the scanned project root so standalone CLI works without booting Laravel.
    'paths' => ['app'],

    'fail_on' => 'high',
    'minimum_confidence' => 'medium',

    'rules' => [
        'GUA-001' => [
            'enabled' => true,
            'queue_after_commit' => null,
            'custom_effects' => [
                App\Payments\AcmeGateway::class.'::capture',
            ],
        ],

        'GUA-002' => [
            'enabled' => true,
            'critical_fields' => [
                App\Models\Wallet::class => ['balance', 'bonus_balance'],
                App\Models\Withdrawal::class => ['status'],
            ],
            'trusted_distributed_locks' => false,
        ],

        'GUA-003' => [
            'enabled' => true,
            'sensitive_fields' => [
                App\Models\Player::class => [
                    'phone',
                    'email',
                    'date_of_birth',
                ],
            ],
            'resource_models' => [
                App\Http\Resources\PlayerResource::class => App\Models\Player::class,
            ],
            'sanitizers' => [
                Illuminate\Support\Str::class.'::mask',
            ],
        ],
    ],
];
```

## Commands

```bash
vendor/bin/guardian scan
vendor/bin/guardian scan --format=json
vendor/bin/guardian scan --format=sarif
vendor/bin/guardian scan --format=junit
vendor/bin/guardian scan --format=gitlab
vendor/bin/guardian scan --fail-on=critical
vendor/bin/guardian scan --min-confidence=high
vendor/bin/guardian baseline
vendor/bin/guardian list
vendor/bin/guardian explain GUA-002
vendor/bin/guardian doctor
vendor/bin/guardian init
```

Laravel aliases:

```bash
php artisan guardian:scan
php artisan guardian:baseline
php artisan guardian:list
php artisan guardian:explain GUA-001
php artisan guardian:doctor
```

Exit codes:

| Code | Meaning |
|---:|---|
| 0 | Scan succeeded and no blocking finding exists |
| 1 | Blocking finding exists |
| 2 | Invalid configuration / analysis setup |
| 3 | Guardian internal failure |

## Severity and confidence are separate

A finding can be:

```text
CRITICAL · HIGH confidence
```

or:

```text
CRITICAL · MEDIUM confidence
```

Severity answers **how bad the failure would be**. Confidence answers **how strongly static analysis proved the path**.

## Baseline

Adopt Guardian on a legacy codebase without blocking every existing issue:

```bash
vendor/bin/guardian baseline
```

This writes stable semantic fingerprints to `guardian-baseline.json`. Subsequent scans still show counts for suppressed/baselined findings but only new active findings block CI.

Refresh the baseline by running the command again after fixing or intentionally accepting findings.

## Narrow suppressions

Inline suppression requires a reason:

```php
// guardian-ignore GUA-003: account owner explicitly requested this field
return response()->json([
    'phone' => $player->phone,
]);
```

Method/class suppression:

```php
use Guardian\Attributes\GuardianIgnore;

#[GuardianIgnore(
    rule: 'GUA-003',
    reason: 'Internal authenticated endpoint restricted to fraud operations',
)]
final class FraudResource extends JsonResource
{
    // ...
}
```

For intentional sensitive exposure, prefer the narrower field-level attribute instead of suppressing all of `GUA-003`:

```php
use Guardian\Attributes\GuardianAllowsSensitive;

#[GuardianAllowsSensitive(
    fields: ['phone'],
    reason: 'The authenticated account owner explicitly requested their own profile phone number.',
)]
public function toArray($request): array
{
    return ['phone' => $this->phone];
}
```

`GuardianAllowsSensitive` can be placed on a resource method or class and only suppresses the listed sensitive fields.

Avoid broad project-wide ignores. A suppression is an explicit correctness exception and should be reviewable.

## PHPStan / Larastan integration

The package ships a PHPStan extension bridge in `extension.neon`.

With PHPStan extension discovery enabled, or by including it manually:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/abdulsalamalkhatib/laravel-guardian/extension.neon
```

A full-directory PHPStan analysis receives Guardian findings as PHPStan errors with identifiers:

```text
guardian.gua001
guardian.gua002
guardian.gua003
```

Guardian metadata includes severity, confidence, and fingerprint. File-only partial PHPStan runs intentionally do not trigger Guardian's whole-project scan.

## CI

Console gate:

```bash
vendor/bin/guardian scan \
  --format=console \
  --fail-on=high \
  --min-confidence=medium
```

SARIF:

```bash
vendor/bin/guardian scan --format=sarif > guardian.sarif
```

GitLab Code Quality:

```bash
vendor/bin/guardian scan --format=gitlab > gl-code-quality-report.json
```

JUnit:

```bash
vendor/bin/guardian scan --format=junit > guardian-junit.xml
```

## Plugin SDK

Guardian's registry supports additional rules and domain adapters without modifying core:

```php
use Guardian\Application\GuardianRegistry;
use Guardian\Contracts\Plugin;

final class PaymentsGuardianPlugin implements Plugin
{
    public function register(GuardianRegistry $registry): void
    {
        $registry
            ->rule(new ProviderIdempotencyRule())
            ->effectProvider(new AcmePaymentEffectProvider())
            ->sanitizer(new InternalTokenRedactor())
            ->concurrencyGuard(new WalletMutexGuard());
    }
}
```

Extension contracts:

- `Rule`
- `Reporter`
- `EffectProvider`
- `SanitizerProvider`
- `ConcurrencyGuardProvider`
- `Plugin`

Future plugin families can live under separate Composer packages such as `Guardian\Concurrency`, `Guardian\Queues`, and `Guardian\Performance` without bloating the V1 core rule set.

## Architecture

```text
SourceFinder
    ↓
PHP parser + resolved names + parent links
    ↓
ProgramIndex
    ├── classes / methods / property types
    ├── structural facts
    └── application call resolution
    ↓
Rule engines
    ├── GUA-001 transaction/effect propagation
    ├── GUA-002 state-dependency/concurrency analysis
    └── GUA-003 sensitivity/serialization taint analysis
    ↓
Finding pipeline
    ├── severity
    ├── confidence
    ├── stable fingerprint
    ├── inline/attribute suppression
    └── baseline
    ↓
Console / JSON / SARIF / JUnit / GitLab
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the detailed design and correctness boundaries.

## Known limits

Static analysis cannot prove all behavior in highly dynamic PHP. Guardian deliberately prefers an explicit confidence level over pretending certainty.

Current V1 limitations include:

- dynamic method names and runtime-selected container bindings may not resolve interprocedurally;
- custom transaction abstractions need a future transaction-provider extension or must wrap supported Laravel primitives;
- GUA-002 is deliberately conservative and does not attempt full symbolic execution;
- GUA-003 is not an authorization theorem prover: conditional authorization lowers confidence rather than proving the exposure safe;
- distributed lock key correctness cannot generally be proven statically.

Zero Guardian findings means **no supported invariant violation was proven**. It does not mean the application is bug-free.

## Development

```bash
composer install
composer test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The integration suite contains both unsafe fixtures that must be detected and safe fixtures that must remain clean.

## License

MIT.
