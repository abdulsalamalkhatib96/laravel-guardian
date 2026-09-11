<?php

declare(strict_types=1);

namespace Guardian\PHPStan;

use Guardian\Application\Guardian;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<CollectedDataNode> */
final class GuardianCollectedDataRule implements Rule
{
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof CollectedDataNode) {
            return [];
        }

        // A file-only PHPStan invocation is very likely a partial analysis.
        // Running Guardian's configured whole-project scan there would surprise users.
        if ($node->isOnlyFilesAnalysis()) {
            return [];
        }

        // Ensure this bridge is only active when at least one class was collected.
        if ($node->get(GuardianTriggerCollector::class) === []) {
            return [];
        }

        try {
            $root = getcwd() ?: '.';
            $result = Guardian::createDefault()->scan($root);
        } catch (\Throwable $e) {
            return [
                RuleErrorBuilder::message('Laravel Guardian failed to run: '.$e->getMessage())
                    ->identifier('guardian.internal')
                    ->build(),
            ];
        }

        $errors = [];
        foreach ($result->activeFindings() as $finding) {
            if (! is_file($finding->location->file)) {
                continue;
            }

            $identifier = 'guardian.'.strtolower(str_replace('-', '', (string) $finding->rule));
            $builder = RuleErrorBuilder::message('['.(string) $finding->rule.'] '.$finding->message)
                ->identifier($identifier)
                ->file($finding->location->file)
                ->line(max(1, $finding->location->line))
                ->metadata([
                    'guardian_rule' => (string) $finding->rule,
                    'severity' => $finding->severity->value,
                    'confidence' => $finding->confidence->value,
                    'fingerprint' => (string) $finding->fingerprint,
                ]);

            if ($finding->remediations !== []) {
                $builder = $builder->tip($finding->remediations[0]);
            }

            $errors[] = $builder->build();
        }

        return $errors;
    }
}
