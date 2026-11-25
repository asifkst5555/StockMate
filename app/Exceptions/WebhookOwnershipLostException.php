<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a webhook worker detects that its processing lease/fencing token
 * is no longer valid before performing a financial side effect (N-01).
 */
class WebhookOwnershipLostException extends RuntimeException
{
}
