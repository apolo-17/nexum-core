<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    */

    'name' => env('HORIZON_NAME', 'Nexum'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'nexum'), '_') . '_horizon:',
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | Authentication is enforced by the HorizonServiceProvider gate.
    | The 'web' middleware is required for session-based auth.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    |
    | Triggers a LongWaitDetected event when a queue backlog exceeds the
    | threshold. Webhooks should be processed quickly (30 s), default
    | jobs have a more relaxed threshold (120 s).
    |
    */

    'waits' => [
        'redis:webhooks' => 30,
        'redis:default'  => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times (minutes)
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent'        => 60,     // 1 hour
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,  // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [],

    'silenced_tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics — snapshot retention (hours)
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB) — master supervisor
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Two supervisors:
    |
    |   supervisor-webhooks — dedicated to the `webhooks` queue.
    |       Processes inbound Singapur relay events (ProcessSingapurWebhook).
    |       Higher priority (nice: 0) and aggressive balancing because relay
    |       events must be picked up quickly.
    |
    |   supervisor-default — handles general background jobs on `default`.
    |       Lower resource footprint, suitable for notifications and misc tasks.
    |
    | The `defaults` block defines shared settings merged into each environment.
    |
    */

    'defaults' => [
        'supervisor-webhooks' => [
            'connection'          => 'redis',
            'queue'               => ['webhooks'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses'        => 1,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 128,
            'tries'               => 3,
            'timeout'             => 120, // ZIP download + parse can take time
            'nice'                => 0,
        ],

        'supervisor-default' => [
            'connection'          => 'redis',
            'queue'               => ['default'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses'        => 1,
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => 128,
            'tries'               => 3,
            'timeout'             => 60,
            'nice'                => 0,
        ],
    ],

    'environments' => [

        /*
         * Production — more workers, aggressive auto-scaling.
         * webhooks: up to 5 workers; relay traffic can spike on business days.
         * default: up to 10 workers; general background work.
         */
        'production' => [
            'supervisor-webhooks' => [
                'maxProcesses'    => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-default' => [
                'maxProcesses'    => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        /*
         * Local — single process per supervisor to keep Docker resource usage low.
         */
        'local' => [
            'supervisor-webhooks' => [
                'maxProcesses' => 1,
            ],
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
        ],

        /*
         * Staging — mirrors production topology at reduced scale.
         */
        'staging' => [
            'supervisor-webhooks' => [
                'maxProcesses'    => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-default' => [
                'maxProcesses'    => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher — restart Horizon on relevant file changes (local only)
    |--------------------------------------------------------------------------
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],
];
