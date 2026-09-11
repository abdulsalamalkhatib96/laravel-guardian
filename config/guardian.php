<?php

declare(strict_types=1);

return [
    // Relative paths are resolved against the scanned project root.
    'paths' => ['app'],

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
    ],

    'fail_on' => 'high',
    'minimum_confidence' => 'medium',
    'baseline' => 'guardian-baseline.json',
    'max_call_depth' => 12,

    'rules' => [
        'GUA-001' => [
            'enabled' => true,
            // null = unknown; true = queue connection defers dispatch until commit.
            'queue_after_commit' => null,
            'custom_effects' => [],
        ],

        'GUA-002' => [
            'enabled' => true,
            'critical_fields' => [],
            'trusted_distributed_locks' => false,
        ],

        'GUA-003' => [
            'enabled' => true,
            'sensitive_fields' => [],
            'resource_models' => [],
            'sanitizers' => [
                'Illuminate\\Support\\Str::mask',
            ],
        ],
    ],

    'reporters' => ['console'],
];
