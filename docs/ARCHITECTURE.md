# Architecture

## Product boundary

Laravel Guardian is a production-correctness analyser. The engine models three classes of boundary failure in V1:

1. **Atomicity boundary** — database transaction vs irreversible external side effect.
2. **Concurrency boundary** — local read/decision/write logic vs concurrent execution.
3. **Exposure boundary** — internal sensitive state vs serialized API output.

The package does not duplicate PHPStan's general type checking or Larastan's Laravel type modelling.

## Pipeline

### 1. Source discovery

`SourceFinder` resolves configured paths recursively and applies normalized exclusions. The standalone command does not require Laravel to boot.

### 2. AST normalization

`PhpAstParser` uses `nikic/php-parser`, then applies:

- `NameResolver`, so imported aliases become fully-qualified names;
- `ParentConnectingVisitor`, so rules can distinguish chains such as `Job::dispatch()->afterCommit()`.

### 3. Program index

`ProgramIndexer` records:

- classes and inheritance;
- methods and parameters;
- typed properties and promoted constructor properties;
- literal property defaults such as Eloquent `$hidden`;
- Guardian suppression attributes;
- structural facts for plugin consumers.

`TypeEnvironment` records model/query assignment origin by source line. This matters because a variable can be acquired once without a lock and later re-acquired with `lockForUpdate()`; Guardian must not incorrectly apply the later lock to an earlier mutation.

### 4. Call resolution

`CallResolver` handles high-confidence application calls for:

- `ClassName::method()`;
- `$this->method()`;
- `$this->typedService->method()`;
- typed parameters and variables whose type is inferred from `new` or static query roots.

Unknown dynamic dispatch remains unresolved rather than guessed.

### 5. Rule engines

Rules implement `Guardian\Contracts\Rule` and consume the shared `ProgramIndex` plus `AnalysisContext`.

The V1 rules intentionally use rule-specific semantic walkers instead of forcing every semantic into a single generic fact format. Structural facts remain available as a low-level extension surface; production rules keep domain-specific state machines where precision requires it.

### 6. Finding pipeline

A `Finding` contains:

- rule id;
- severity;
- confidence;
- location and symbol;
- risk explanation;
- evidence;
- trace;
- remediation strategies;
- semantic fingerprint;
- tags.

Findings are deduplicated by fingerprint, then inline/attribute suppressions and baseline entries are applied.

### 7. Reporting

Reporters are pure output adapters:

- console;
- JSON;
- SARIF 2.1.0;
- JUnit XML;
- GitLab Code Quality JSON.

No reporter mutates analysis results.

## GUA-001 state model

The rule tracks whether execution is inside an active transaction.

Supported transaction entries include:

- `DB::transaction()`;
- `DB::connection(...)->transaction()`;
- typed connection `->transaction()`;
- manual `beginTransaction()` / `commit()` / `rollBack()` in sequential blocks.

When the rule sees an application call while transaction state is active, it follows the resolved target method up to `max_call_depth` with recursion protection.

Effects are classified separately from call resolution. `afterCommit()` and a statically-known `queue_after_commit=true` are recognized for queued work.

## GUA-002 state model

The rule looks for a value dependency, not merely a `save()` call.

Examples of unsafe dependencies:

```text
Wallet.balance read
    ↓
Wallet.balance + amount
    ↓
Wallet.balance assignment
    ↓
save()
```

and:

```text
Wallet.balance read
    ↓
delta = target - balance
    ↓
increment(balance, delta)
```

The second example is important: `increment()` is atomic, but the delta is not independent of stale state.

Protection is considered proven when the model acquisition containing `lockForUpdate()` dominates the mutation by source order **and** the read/write region is inside an active transaction.

A configured distributed lock can be trusted, but this is opt-in because static analysis generally cannot prove that a lock key uniquely represents the mutated resource.

## GUA-003 taint model

Sensitive sources come from explicit configuration, Eloquent `$hidden`, and a conservative secret-name list.

Sources propagate through simple local assignments into serialization sinks. Sanitizer calls terminate propagation for their subtree; Guardian intentionally does not descend into sanitizer arguments after recognizing an approved sanitizer.

Authorization-related resource helpers such as `when()` lower confidence rather than automatically suppressing the finding.

## Fingerprints

Fingerprints intentionally exclude line numbers. They use semantic identity such as:

- rule;
- symbol;
- sink or model/field;
- normalized relevant expression/kind.

Moving code up or down a file therefore does not invalidate the baseline.

## PHPStan bridge

`GuardianTriggerCollector` causes PHPStan to emit a `CollectedDataNode` during a full-project analysis. `GuardianCollectedDataRule` then runs the Guardian whole-program engine once and maps active findings to PHPStan `RuleError`s.

This bridge exists for CI/tooling interoperability. Guardian's own interprocedural analysis remains independent so `vendor/bin/guardian` can operate without booting Laravel or depending on PHPStan's internal DI container.

## Extension boundary

`GuardianRegistry` is the stable composition point. Plugins can add rules, reporters, external-effect detectors, sanitizers, and concurrency guards.

A V1 design constraint is that plugins may extend semantics but cannot silently weaken built-in rules.
