<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Cancellation;

/**
 * Indicate that an asynchronous operation was cancelled.
 */
final class CancelledException extends \RuntimeException {}
