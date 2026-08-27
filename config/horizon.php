<?php

use App\Http\Middleware\AuthenticateHorizon;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    // `env()` is untyped and returns `bool|string` — an `APP_NAME` of `true`
    // or `false` is read as a boolean — so the value is coerced at this
    // boundary before `Str::slug()`, which takes a string. Published as-is by
    // `horizon:install`; corrected here rather than excluded from analysis.
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web', AuthenticateHorizon::class],

    /*
    |--------------------------------------------------------------------------
    | Horizon Dashboard Credentials
    |--------------------------------------------------------------------------
    |
    | The username and password the browser prompts for when opening the
    | Horizon dashboard, checked by `AuthenticateHorizon`. This application
    | has no superadmin role, so dashboard access is deployment configuration
    | rather than a property of a user account.
    |
    | Both are required. If either is missing or empty the middleware rejects
    | every request, so an unconfigured deployment gets a locked dashboard
    | rather than an open one.
    |
    */

    'basic_auth' => [
        'username' => env('HORIZON_USERNAME'),
        'password' => env('HORIZON_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        /*
         * Outbound delivery attempts (the `webhooks` queue, named by
         * `config('ingest.webhooks_queue')`). These are async-mode fan-out
         * jobs: one HTTP send each, capped at 15 seconds by
         * `DeliverToDestination::TIMEOUT_SECONDS`. Short, numerous, and
         * independent of one another, so this is the supervisor that scales.
         */
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            // Must stay 1. `DeliverToDestination` declares `$tries = 1`
            // because retry is the application's own concern (ADR-015's
            // policy, schedule and terminal state). A queue-level retry would
            // re-send a webhook outside that policy — a duplicate delivery
            // the retry ledger has no record of.
            'tries' => 1,
            // Comfortably above the 15-second HTTP timeout, leaving room for
            // connection setup and payload work, and well under the
            // connection's `retry_after`.
            'timeout' => 60,
            'nice' => 0,
        ],

        /*
         * Everything else: the FIFO advancer (`AdvanceProxyFifoQueue`), the
         * per-minute sweepers, retention and mail.
         *
         * The long timeout is not padding. In FIFO mode `DeliverStep` runs
         * each destination *inline* rather than fanning out, so one advancer
         * job is N sequential HTTP sends of up to 15 seconds each, plus the
         * settle. A proxy with a dozen destinations is a legitimately
         * multi-minute job, and if it outlives the connection's `retry_after`
         * Redis makes it visible again and a second worker re-runs it —
         * duplicating every send it had already made. `retry_after` is set
         * well above this in `config/queue.php`; see the note there.
         */
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-webhooks' => [
                'minProcesses' => 2,
                'maxProcesses' => 20,
                'balanceMaxShift' => 3,
                'balanceCooldown' => 3,
            ],

            // Concurrency here is safe: FIFO ordering is held by an atomic
            // `FOR UPDATE` claim in `AdvanceProxyFifoQueue::claimNext()`, not
            // by there being a single worker. Its `WithoutOverlapping` job
            // middleware is documented as a thundering-herd reducer, not the
            // ordering guard — a redundant advancer that loses the claim is
            // simply dropped. Several workers therefore serve different
            // proxies in parallel while each proxy's own line stays ordered.
            'supervisor-default' => [
                'minProcesses' => 1,
                'maxProcesses' => 8,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-webhooks' => [
                'maxProcesses' => 3,
            ],

            'supervisor-default' => [
                'maxProcesses' => 2,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
