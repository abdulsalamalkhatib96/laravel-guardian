<?php

declare(strict_types=1);

namespace Guardian\Application;

use Guardian\Analysis\AnalysisContext;
use Guardian\Analysis\PhpAstParser;
use Guardian\Analysis\ProgramBuilder;
use Guardian\Analysis\ProgramIndexer;
use Guardian\Analysis\SourceFinder;
use Guardian\Domain\Confidence;
use Guardian\Domain\Finding;
use Guardian\Domain\ScanResult;
use Guardian\Domain\Severity;
use Guardian\Reporting\ConsoleReporter;
use Guardian\Reporting\GitlabReporter;
use Guardian\Reporting\JsonReporter;
use Guardian\Reporting\JunitReporter;
use Guardian\Reporting\SarifReporter;
use Guardian\Rules\GUA001\ExternalSideEffectInTransactionRule;
use Guardian\Rules\GUA002\UnsafeEloquentReadModifyWriteRule;
use Guardian\Rules\GUA003\SensitiveFieldExposureRule;
use Guardian\Support\Configuration;
use Guardian\Support\ConfigurationLoader;
use Guardian\Suppression\Baseline;
use Guardian\Suppression\SuppressionMatcher;

final class Guardian
{
    private GuardianRegistry $registry;

    public function __construct(
        private readonly ConfigurationLoader $configLoader,
        ?GuardianRegistry $registry = null,
    ) {
        $this->registry = $registry ?? self::defaultRegistry();
    }

    public static function createDefault(): self
    {
        return new self(new ConfigurationLoader(dirname(__DIR__, 2).'/config/guardian.php'));
    }

    public static function defaultRegistry(): GuardianRegistry
    {
        return (new GuardianRegistry())
            ->rule(new ExternalSideEffectInTransactionRule())
            ->rule(new UnsafeEloquentReadModifyWriteRule())
            ->rule(new SensitiveFieldExposureRule())
            ->reporter(new ConsoleReporter())
            ->reporter(new JsonReporter())
            ->reporter(new SarifReporter())
            ->reporter(new JunitReporter())
            ->reporter(new GitlabReporter());
    }

    public function registry(): GuardianRegistry
    {
        return $this->registry;
    }

    /** @param array<string, mixed> $overrides */
    public function scan(string $basePath, array $overrides = [], ?string $explicitConfig = null): ScanResult
    {
        $started = microtime(true);
        $basePath = realpath($basePath) ?: $basePath;
        $config = $this->configLoader->load($basePath, $explicitConfig, $overrides);
        $program = (new ProgramBuilder(new SourceFinder(), new PhpAstParser(), new ProgramIndexer()))
            ->build($config->paths(), $config->excludes());

        $baselinePath = $config->get('baseline');
        $baseline = Baseline::load(is_string($baselinePath) ? $baselinePath : null);
        $suppressions = new SuppressionMatcher();
        $context = new AnalysisContext($config, $basePath, $baseline, $suppressions, $this->registry);

        $findings = [];
        foreach ($this->registry->rules() as $rule) {
            if (! $config->ruleEnabled((string) $rule->id())) {
                continue;
            }
            foreach ($rule->analyse($program, $context) as $finding) {
                $findings[] = $this->applySuppression($finding, $program, $context);
            }
        }

        $findings = $this->deduplicate($findings);
        usort($findings, static function (Finding $a, Finding $b): int {
            $comparison = $b->severity->rank() <=> $a->severity->rank();
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = $b->confidence->rank() <=> $a->confidence->rank();
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = strcmp($a->location->file, $b->location->file);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = $a->location->line <=> $b->location->line;
            if ($comparison !== 0) {
                return $comparison;
            }
            return strcmp((string) $a->rule, (string) $b->rule);
        });

        return new ScanResult(
            $findings,
            [
                'files' => count($program->files()),
                'classes' => count($program->classes()),
                'methods' => count($program->methods()),
                'active_rules' => count(array_filter($this->registry->rules(), static fn ($rule): bool => $config->ruleEnabled((string) $rule->id()))),
            ],
            microtime(true) - $started,
        );
    }

    public function render(ScanResult $result, string $format, string $basePath): string
    {
        $reporter = $this->registry->findReporter($format);
        if ($reporter === null) {
            throw new \InvalidArgumentException("Unsupported Guardian reporter: {$format}");
        }
        return $reporter->render($result, $basePath);
    }

    public function exitCode(ScanResult $result, Configuration $config): int
    {
        $severity = Severity::fromString((string) $config->get('fail_on', 'high'));
        $confidence = Confidence::fromString((string) $config->get('minimum_confidence', 'medium'));

        foreach ($result->activeFindings() as $finding) {
            if ($finding->severity->rank() >= $severity->rank() && $finding->confidence->rank() >= $confidence->rank()) {
                return 1;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $overrides */
    public function configuration(string $basePath, array $overrides = [], ?string $explicitConfig = null): Configuration
    {
        return $this->configLoader->load($basePath, $explicitConfig, $overrides);
    }

    private function applySuppression(Finding $finding, \Guardian\Analysis\ProgramIndex $program, AnalysisContext $context): Finding
    {
        $reason = $context->suppressions->reasonFor($finding, $program);
        if ($reason !== null) {
            return $finding->withSuppression($reason);
        }
        if ($context->baseline->contains($finding)) {
            return $finding->withSuppression('baseline: '.($context->baseline->reason($finding) ?? 'accepted finding'));
        }

        return $finding;
    }

    /** @param list<Finding> $findings @return list<Finding> */
    private function deduplicate(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $key = (string) $finding->fingerprint;
            if (! isset($unique[$key]) || ($unique[$key]->suppressed && ! $finding->suppressed)) {
                $unique[$key] = $finding;
            }
        }
        return array_values($unique);
    }
}
