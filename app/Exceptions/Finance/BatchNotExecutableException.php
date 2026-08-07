<?php

namespace App\Exceptions\Finance;

use RuntimeException;

/**
 * Thrown when a Mass Billing batch is not in a state that may be executed
 * (e.g. it is already processing/completed, has failed, or was never
 * previewed). Carries a stable machine reason code so the controller can map
 * it to a translated message without leaking Russian text as the identifier.
 */
class BatchNotExecutableException extends RuntimeException
{
    public const REASON_ALREADY_PROCESSING = 'already_processing';
    public const REASON_ALREADY_COMPLETED = 'already_completed';
    public const REASON_PREVIOUSLY_FAILED = 'previously_failed';
    public const REASON_NOT_PREVIEWED = 'not_previewed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Billing batch is not executable: {$reason}");
    }
}
