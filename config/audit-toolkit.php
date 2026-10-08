<?php

use Digit7s\AuditToolkit\Context\DefaultAuditContextResolver;

return [
    'table' => 'audit_events',

    'failure_mode' => 'fail_closed',

    'connections' => [
        'default' => null,
        'strict_atomicity' => false,
    ],

    'context' => [
        // An empty list safely inspects configured guards and refuses ambiguous identities.
        'guard_priority' => [],
        'resolver' => DefaultAuditContextResolver::class,
        'request_id_header' => 'X-Request-ID',
        'correlation_id_header' => 'X-Correlation-ID',
        'generate_correlation_id' => true,
        'include_ip' => false,
        'include_user_agent' => false,
    ],

    'privacy' => [
        'values_allowed_keys' => [],
        'metadata_allowed_keys' => [
            'source',
            'route',
            'method',
            'request_id',
            'correlation_id',
            'batch_id',
            'guard',
            'channel',
            'ip_address',
            'user_agent',
            'deletion_mode',
            'execution_source',
            'original_execution_source',
            'authentication_event',
            'known_account',
        ],
        'redacted_value' => '[REDACTED]',
    ],

    'authentication' => [
        'enabled' => false,
        // Native Laravel events only. Host-specific security events are not inferred.
        'events' => [],
        // Failed-login records are intentionally capped per HTTP request.
        'max_failed_per_request' => 1,
    ],
];
