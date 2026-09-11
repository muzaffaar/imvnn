<?php

return [
    /*
    | Queue lifecycle logs are useful while commissioning a deployment, but
    | can be noisy when a busy source fans out hundreds of candidates. Final
    | failures and retries are always logged regardless of this switch.
    */
    'queue_lifecycle' => env('PIPELINE_LOG_QUEUE_LIFECYCLE', false),

    /*
    | Candidate skip reasons are diagnostic rather than failures. Leave this
    | off in steady state; enable it temporarily when tuning a source.
    */
    'candidate_skips' => env('PIPELINE_LOG_CANDIDATE_SKIPS', false),
];
