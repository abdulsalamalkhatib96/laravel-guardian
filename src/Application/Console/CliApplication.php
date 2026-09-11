<?php

declare(strict_types=1);

namespace Guardian\Application\Console;

use Guardian\Application\Guardian;
use Guardian\Domain\Finding;
use Guardian\Suppression\Baseline;

final class CliApplication
{
    public function __construct(private readonly Guardian $guardian) {}

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'scan';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'scan' => $this->scan($args),
                'baseline' => $this->baseline($args),
                'list' => $this->listRules(),
                'explain' => $this->explain($args),
                'doctor' => $this->doctor($args),
                'init' => $this->init($args),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            fwrite(STDERR, 'Guardian configuration/analysis error: '.$e->getMessage().PHP_EOL);
            return 2;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Guardian internal error: '.$e->getMessage().PHP_EOL);
            if (getenv('GUARDIAN_DEBUG') === '1') {
                fwrite(STDERR, $e->getTraceAsString().PHP_EOL);
            }
            return 3;
        }
    }

    /** @param list<string> $args */
    private function scan(array $args): int
    {
        $options = $this->options($args);
        $base = $this->basePath($options);
        $overrides = $this->scanOverrides($options);
        $configFile = $this->stringOption($options, 'config');
        $format = $this->stringOption($options, 'format') ?? 'console';

        $result = $this->guardian->scan($base, $overrides, $configFile);
        if (! isset($options['quiet'])) {
            fwrite(STDOUT, $this->guardian->render($result, $format, $base));
        }

        $config = $this->guardian->configuration($base, $overrides, $configFile);
        return $this->guardian->exitCode($result, $config);
    }

    /** @param list<string> $args */
    private function baseline(array $args): int
    {
        $options = $this->options($args);
        $base = $this->basePath($options);
        $configFile = $this->stringOption($options, 'config');
        $config = $this->guardian->configuration($base, [], $configFile);
        $path = $this->stringOption($options, 'output') ?? (is_string($config->get('baseline')) ? $config->get('baseline') : $base.'/guardian-baseline.json');
        if (! $this->isAbsolutePath($path)) {
            $path = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($path, '/\\');
        }

        $overrides = $this->scanOverrides($options);
        $overrides['baseline'] = null;
        $result = $this->guardian->scan($base, $overrides, $configFile);

        $findings = array_values(array_filter(
            $result->findings,
            static fn (Finding $finding): bool => ! ($finding->suppressed && str_starts_with((string) $finding->suppressionReason, 'inline:')),
        ));
        Baseline::write($path, $findings, $this->stringOption($options, 'reason') ?? 'accepted legacy finding');
        fwrite(STDOUT, sprintf("Baseline written: %s (%d findings)%s", $path, count($findings), PHP_EOL));

        return 0;
    }

    private function listRules(): int
    {
        foreach ($this->guardian->registry()->rules() as $rule) {
            fwrite(STDOUT, sprintf("%-8s %s%s", (string) $rule->id(), $rule->title(), PHP_EOL));
        }
        return 0;
    }

    /** @param list<string> $args */
    private function explain(array $args): int
    {
        $id = $args[0] ?? '';
        $rule = $this->guardian->registry()->findRule($id);
        if ($rule === null) {
            fwrite(STDERR, "Unknown Guardian rule: {$id}".PHP_EOL);
            return 2;
        }

        $doc = dirname(__DIR__, 3).'/docs/rules/'.$id.'.md';
        if (is_file($doc)) {
            fwrite(STDOUT, (string) file_get_contents($doc));
            return 0;
        }

        fwrite(STDOUT, (string) $rule->id().' — '.$rule->title().PHP_EOL);
        return 0;
    }

    /** @param list<string> $args */
    private function doctor(array $args): int
    {
        $options = $this->options($args);
        $base = $this->basePath($options);
        $config = $this->guardian->configuration($base, [], $this->stringOption($options, 'config'));
        $checks = [
            ['PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['nikic/php-parser available', class_exists(\PhpParser\ParserFactory::class)],
            ['PHPStan available', class_exists(\PHPStan\Analyser\Scope::class)],
            ['Larastan available', $this->packageInstalled('larastan/larastan')],
        ];

        foreach ($config->paths() as $path) {
            $checks[] = ['scan path exists: '.$path, file_exists($path)];
        }

        $ok = true;
        foreach ($checks as [$label, $passed]) {
            $ok = $ok && $passed;
            fwrite(STDOUT, sprintf('[%s] %s%s', $passed ? 'OK' : 'FAIL', $label, PHP_EOL));
        }

        return $ok ? 0 : 2;
    }

    private function packageInstalled(string $package): bool
    {
        return class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled($package);
    }

    /** @param list<string> $args */
    private function init(array $args): int
    {
        $options = $this->options($args);
        $base = $this->basePath($options);
        $target = $base.'/config/guardian.php';
        if (is_file($target) && ! isset($options['force'])) {
            fwrite(STDERR, "Guardian config already exists: {$target} (use --force to overwrite)".PHP_EOL);
            return 2;
        }
        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0777, true) && ! is_dir(dirname($target))) {
            throw new \RuntimeException('Unable to create config directory.');
        }
        $source = dirname(__DIR__, 3).'/config/guardian.php';
        if (! copy($source, $target)) {
            throw new \RuntimeException('Unable to publish Guardian config.');
        }
        fwrite(STDOUT, "Guardian config created: {$target}".PHP_EOL);
        return 0;
    }

    private function help(): int
    {
        fwrite(STDOUT, <<<'TXT'
Laravel Guardian — production correctness engine

Usage:
  guardian scan [--root=PATH] [--path=PATH[,PATH]] [--format=console|json|sarif|junit|gitlab]
                [--fail-on=critical|high|medium|low] [--min-confidence=high|medium|low]
                [--config=FILE] [--baseline=FILE]
  guardian baseline [--root=PATH] [--output=FILE] [--reason=TEXT]
  guardian list
  guardian explain GUA-001
  guardian doctor [--root=PATH]
  guardian init [--root=PATH] [--force]

Exit codes:
  0 no blocking findings / command succeeded
  1 blocking findings detected
  2 invalid configuration or analysis setup
  3 Guardian internal error

TXT);
        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown Guardian command: {$command}".PHP_EOL);
        return 2;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function scanOverrides(array $options): array
    {
        $overrides = [];
        if (($path = $this->stringOption($options, 'path')) !== null) {
            $overrides['paths'] = array_values(array_filter(array_map('trim', explode(',', $path))));
        }
        if (($fail = $this->stringOption($options, 'fail-on')) !== null) {
            $overrides['fail_on'] = $fail;
        }
        if (($confidence = $this->stringOption($options, 'min-confidence')) !== null) {
            $overrides['minimum_confidence'] = $confidence;
        }
        if (($baseline = $this->stringOption($options, 'baseline')) !== null) {
            $overrides['baseline'] = $baseline;
        }
        return $overrides;
    }

    /** @param list<string> $args @return array<string, mixed> */
    private function options(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (! str_starts_with($arg, '--')) {
                $options[] = $arg;
                continue;
            }
            $raw = substr($arg, 2);
            if (str_contains($raw, '=')) {
                [$key, $value] = explode('=', $raw, 2);
                $options[$key] = $value;
            } else {
                $options[$raw] = true;
            }
        }
        return $options;
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
        );
    }

    /** @param array<string, mixed> $options */
    private function basePath(array $options): string
    {
        $root = $this->stringOption($options, 'root') ?? getcwd();
        return realpath($root) ?: $root;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string $key): ?string
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== '' ? $options[$key] : null;
    }
}
