<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

/**
 * Thrown after connection acquisition exceeds the configured timeout.
 *
 * This occurs when all connections are in use and the maximum
 * concurrency limit has been reached for the target host.
 */
class ConnectionAcquireTimeoutException extends \RuntimeException {}
