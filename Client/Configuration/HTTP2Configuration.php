<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Configuration;

/**
 * HTTP/2-specific connection settings.
 *
 * Values are used to configure the HTTP/2 connection client settings frame.
 */
final class HTTP2Configuration
{
    /**
     * @throws \InvalidArgumentException If the stream limit is invalid.
     */
    public function __construct(
        /** Maximum number of concurrent streams per HTTP/2 connection. */
        public readonly int $maxStreams = 100
    )
    {
        if ($maxStreams < 1) {
            throw new \InvalidArgumentException(
                'Maximum HTTP/2 streams must be at least 1.'
            );
        }
    }
}
