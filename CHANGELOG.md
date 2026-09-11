# Changelog

## 1.0.0 - 2026-09-11

Initial production-correctness engine:

- GUA-001 external side effects inside DB transactions;
- GUA-002 unsafe Eloquent read-modify-write and check-then-act;
- GUA-003 sensitive API serialization exposure;
- standalone CLI and Laravel Artisan integration;
- baseline, inline/attribute suppression, semantic fingerprints;
- console, JSON, SARIF, JUnit, and GitLab reporters;
- PHPStan bridge;
- plugin registry for rules, effects, sanitizers, concurrency guards, and reporters.
