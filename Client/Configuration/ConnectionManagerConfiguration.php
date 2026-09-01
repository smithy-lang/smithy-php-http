<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Configuration;

/**
 * Configure HTTP connection pooling.
 */
final class ConnectionManagerConfiguration
{
    /**
     * @throws \InvalidArgumentException If a limit or timeout is invalid.
     */
    public function __construct(
        /** Maximum concurrent requests per host. */
        public readonly int $maxConcurrency = 100,

        /** Maximum idle handles retained per host. */
        public readonly int $maxIdlePerHost = 100,

        /** Maximum idle handles retained across all hosts. */
        public readonly int $maxIdleTotal = 100,

        /** Idle timeout in seconds. */
        public readonly float $idleTimeout = 60.0,

        /**
         * Maximum time to wait for a connection. Zero disables the timeout.
         */
        public readonly float $connectionAcquireTimeout = 10.0
    )
    {
        if ($maxConcurrency < 1) {
            throw new \InvalidArgumentException(
                'Maximum concurrency must be at least 1.'
            );
        }

        if ($maxIdlePerHost < 0 || $maxIdleTotal < 0) {
            throw new \InvalidArgumentException(
                'Idle connection limits must be non-negative.'
            );
        }

        if ($maxIdleTotal < $maxIdlePerHost) {
            throw new \InvalidArgumentException(
                'Maximum total idle connections must be greater than '
                    . 'or equal to the per-host limit.'
            );
        }

        if (!\is_finite($idleTimeout) || $idleTimeout <= 0.0) {
            throw new \InvalidArgumentException(
                'Idle timeout must be finite and positive.'
            );
        }

        if (!\is_finite($connectionAcquireTimeout)
            || $connectionAcquireTimeout < 0.0
        ) {
            throw new \InvalidArgumentException(
                'Connection acquisition timeout must be finite and '
                    . 'non-negative.'
            );
        }
    }
}
