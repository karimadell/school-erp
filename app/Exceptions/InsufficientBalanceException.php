<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The student's outstanding balance exceeds an admin-configured threshold
 * (amount and/or age), and the fee being subscribed to is not marked
 * exempt (policy decision 4).
 */
class InsufficientBalanceException extends RuntimeException
{
}
