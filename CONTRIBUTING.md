# Contributing

Guardian values precision over rule count.

Before proposing a new rule, define the production invariant it protects and show a deterministic failure scenario. A generic style preference does not belong in Guardian.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Every rule change must include unsafe and safe fixtures. Regression fixes for false positives should add a fixture reproducing the old behavior.

## Compatibility

Do not depend on Laravel application boot for the standalone scanner unless the feature explicitly requires runtime metadata. The core scanner must remain usable in CI even when the application cannot boot.
