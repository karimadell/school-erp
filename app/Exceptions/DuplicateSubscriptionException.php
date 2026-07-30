<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A student's enrollment already has a subscription to this fee. Reuses
 * Enrollment's existing one-row-per-(student, academic_year) guarantee
 * (Batch 3) rather than tracking a year separately on the subscription
 * itself (policy decision 3).
 */
class DuplicateSubscriptionException extends RuntimeException
{
}
