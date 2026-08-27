<?php

declare(strict_types=1);

namespace Smithy\Http\Client;

/**
 * Configure transport behavior for one HTTP request.
 */
final class HttpRequestOptions
{
    private const MIN_UPLOAD_BUFFER_SIZE = 16 * 1024;
    private const MAX_UPLOAD_BUFFER_SIZE = 2 * 1024 * 1024;
    private const MIN_BUFFERED_DOWNLOAD_BUFFER_SIZE = 16 * 1024;
    private const MAX_BUFFERED_DOWNLOAD_BUFFER_SIZE = 10 * 1024 * 1024;
    private const MIN_RESPONSE_BUFFER_SIZE = 16 * 1024;

    /**
     * Create request options.
     *
     * @throws \InvalidArgumentException If a timeout, limit, or buffer size
     *                                   is invalid.
     */
    public function __construct(
        /**
         * Request timeout in seconds. 0 = no limit.
         */
        public readonly float $timeout = 30.0,

        /** Whether to follow redirects. */
        public readonly bool $followRedirects = false,

        /** Proxy URL (e.g. "http://proxy:8080"), or null for direct. */
        public readonly ?string $proxy = null,

        /** Whether to decode content-encoded (gzip) responses. */
        public readonly bool $decodeContent = true,

        /** Request trailer fields for transports that support them. */
        public readonly ?RequestTrailers $trailers = null,

        /** Maximum buffered response bytes before transport backpressure. */
        public readonly int $responseBufferSize = 64 * 1024,

        /** Largest response body buffered automatically in memory. */
        public readonly int $maxBufferedResponseBytes = 1024 * 1024,

        /** Response read inactivity timeout in seconds. 0 = no limit. */
        public readonly float $readTimeout = 30.0,

        /** Maximum decoded response body bytes. 0 = no limit. */
        public readonly int $maxResponseBodyBytes = 0,

        /** Preferred transport upload buffer size in bytes. */
        public readonly int $uploadBufferSize = 128 * 1024,

        /** Preferred buffer size for buffered response downloads. */
        public readonly int $bufferedDownloadBufferSize = 256 * 1024
    )
    {
        if (!\is_finite($timeout)
            || $timeout < 0.0
            || !\is_finite($readTimeout)
            || $readTimeout < 0.0
        ) {
            throw new \InvalidArgumentException(
                'HTTP timeouts must be finite and non-negative.'
            );
        }

        if ($responseBufferSize < self::MIN_RESPONSE_BUFFER_SIZE) {
            throw new \InvalidArgumentException(
                'Response buffer size must be at least 16384 bytes.'
            );
        }

        if ($maxBufferedResponseBytes < 0) {
            throw new \InvalidArgumentException(
                'Maximum automatically buffered response size must be '
                    . 'non-negative.'
            );
        }

        if ($uploadBufferSize < self::MIN_UPLOAD_BUFFER_SIZE
            || $uploadBufferSize > self::MAX_UPLOAD_BUFFER_SIZE
        ) {
            throw new \InvalidArgumentException(
                'Upload buffer size must be between 16384 and '
                    . '2097152 bytes.'
            );
        }

        if ($bufferedDownloadBufferSize !== 0
            && (
                $bufferedDownloadBufferSize
                    < self::MIN_BUFFERED_DOWNLOAD_BUFFER_SIZE
                || $bufferedDownloadBufferSize
                    > self::MAX_BUFFERED_DOWNLOAD_BUFFER_SIZE
            )
        ) {
            throw new \InvalidArgumentException(
                'Buffered download buffer size must be 0 or between '
                    . '16384 and 10485760 bytes.'
            );
        }

        if ($maxResponseBodyBytes < 0) {
            throw new \InvalidArgumentException(
                'Maximum response body size must be non-negative.'
            );
        }
    }
}
