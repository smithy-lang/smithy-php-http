<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Smithy\Http\Message\StreamingResponseStream;
use Smithy\Http\Message\ResponseTrailers;

/**
 * Track response headers and download flow-control state.
 *
 * @internal
 */
final class CurlResponseState
{
    public int $status = 0;
    public string $protocolVersion = '1.1';
    public string $reason = '';

    /** @var array<string, list<string>> Final response headers. */
    public array $headers = [];

    public bool $headersComplete = false;
    public bool $responseDelivered = false;
    public bool $acceptBody = false;
    public bool $paused = false;
    public bool $resumeScheduled = false;
    public int $pausedChunkLength = 0;
    public ?\Throwable $bodyWriteError = null;
    private int $currentStatus = 0;
    private string $currentProtocolVersion = '1.1';
    private string $currentReason = '';
    /** @var array<string, list<string>> */
    private array $currentHeaders = [];
    /** @var array<string, list<string>> */
    private array $trailerHeaders = [];
    private ?float $lastReadActivityAt = null;
    private int $receivedBodyBytes = 0;
    private string $bufferedBody = '';
    private ?StreamingResponseStream $ownedBody;
    private ?\WeakReference $bodyReference = null;
    private readonly ResponseTrailers $trailers;
    private bool $proxyConnectResponseIgnored = false;

    public function __construct(
        ?StreamingResponseStream $body,
        private readonly bool $decodeContent = true,
        private readonly int $maxResponseBodyBytes = 0,
        private readonly bool $responseBodyAllowed = true,
        private readonly ?\Closure $onReadTimeoutStarted = null,
        private readonly int $maxBufferedResponseBytes =
            1024 * 1024,
        private readonly bool $ignoreProxyConnectResponse = false
    )
    {
        $this->ownedBody = $body;
        $this->trailers = ResponseTrailers::pending();
    }

    /**
     * Get the streamed response body while it remains available.
     */
    public function body(): ?StreamingResponseStream
    {
        if ($this->ownedBody !== null) {
            return $this->ownedBody;
        }

        $body = $this->bodyReference?->get();

        return $body instanceof StreamingResponseStream ? $body : null;
    }

    /**
     * Determine whether this transfer uses a streamed response body.
     */
    public function isStreaming(): bool
    {
        return $this->ownedBody !== null
            || $this->bodyReference !== null;
    }

    /**
     * Determine whether a streamed response can be delivered.
     */
    public function isStreamedResponseReady(): bool
    {
        return $this->headersComplete
            && $this->body() !== null
            && !$this->responseDelivered
            && $this->bodyWriteError === null;
    }

    /**
     * Determine whether final response headers require streaming.
     */
    public function shouldStreamResponseBody(): bool
    {
        if (!$this->headersComplete) {
            throw new \LogicException(
                'Response body delivery cannot be selected before final '
                    . 'headers.'
            );
        }

        if (!$this->responseBodyAllowed
            || !$this->statusAllowsResponseBody()
        ) {
            return false;
        }

        $contentLength = $this->responseContentLength();

        return $contentLength === null
            || $contentLength > $this->maxBufferedResponseBytes;
    }

    /**
     * Attach a lazily created streamed response body.
     *
     * @return void
     */
    public function activateStreamingBody(StreamingResponseStream $body): void
    {
        if ($this->isStreaming()) {
            throw new \LogicException(
                'Streamed response body is already active.'
            );
        }

        $this->ownedBody = $body;
    }

    /**
     * Determine whether response bytes require a PHP write callback.
     */
    public function requiresWriteCallback(): bool
    {
        return $this->isStreaming() || $this->buffersResponseBody();
    }

    /**
     * Determine whether a paused response callback can resume.
     */
    public function canResume(): bool
    {
        return $this->body()?->canResume(
            $this->pausedChunkLength
        ) ?? false;
    }

    /**
     * Get the shared response trailer state.
     */
    public function trailers(): ResponseTrailers
    {
        return $this->trailers;
    }

    /**
     * Transfer strong ownership of the response body to the caller.
     *
     * @return void
     */
    public function releaseBodyOwnership(): void
    {
        if ($this->ownedBody === null) {
            return;
        }

        $this->bodyReference = \WeakReference::create($this->ownedBody);
        $this->ownedBody = null;
    }

    /**
     * Start or reset the response read inactivity timer.
     *
     * @return void
     */
    public function recordReadActivity(): void
    {
        $started = $this->lastReadActivityAt === null;
        $this->lastReadActivityAt = \microtime(true);

        if ($started) {
            $this->onReadTimeoutStarted?->__invoke();
        }
    }

    /**
     * Get the response read inactivity deadline.
     */
    public function readTimeoutDeadline(float $timeout): ?float
    {
        if ($timeout <= 0.0
            || $this->lastReadActivityAt === null
        ) {
            return null;
        }

        return $this->lastReadActivityAt + $timeout;
    }

    /**
     * Determine whether response reads exceeded the inactivity timeout.
     */
    public function readTimedOut(float $timeout, float $now): bool
    {
        $deadline = $this->readTimeoutDeadline($timeout);

        return $deadline !== null && $now >= $deadline;
    }

    /**
     * Determine whether response read timeout tracking started.
     */
    public function isReadTimeoutStarted(): bool
    {
        return $this->lastReadActivityAt !== null;
    }

    /**
     * Consume one header callback line.
     *
     * @return bool True when final response headers completed.
     */
    public function consumeHeader(string $header): bool
    {
        $this->recordReadActivity();
        $trimmed = \trim($header);

        if (\preg_match(
            '/^HTTP\/(\S+)\s+(\d{3})(?:\s+(.*))?$/i',
            $trimmed,
            $matches
        ) === 1) {
            if ($this->headersComplete) {
                $this->trailerHeaders = [];
            }

            $this->headersComplete = false;
            $this->currentProtocolVersion = $matches[1];
            $this->currentStatus = (int) $matches[2];
            $this->currentReason = $matches[3] ?? '';
            $this->currentHeaders = [];
            $this->acceptBody = false;

            return false;
        }

        if ($trimmed === '') {
            if ($this->headersComplete) {
                return false;
            }

            if ($this->currentStatus === 0 || $this->isInterimResponse()) {
                return false;
            }

            $this->status = $this->currentStatus;
            $this->protocolVersion = $this->currentProtocolVersion;
            $this->reason = $this->currentReason;
            $this->headers = $this->currentHeaders;
            $this->normalizeDecodedHeaders();
            $this->headersComplete = true;
            $this->acceptBody = true;

            return true;
        }

        if (!\str_contains($trimmed, ':')) {
            return false;
        }

        [$name, $value] = \explode(':', $trimmed, 2);
        $name = \trim($name);
        $value = \trim($value);

        if ($this->headersComplete) {
            $this->appendHeader(
                $this->trailerHeaders,
                $name,
                $value
            );

            return false;
        }

        $this->appendHeader(
            $this->currentHeaders,
            $name,
            $value
        );

        return false;
    }

    /**
     * Consume one decoded response body chunk.
     */
    public function consumeBody(string $chunk): int
    {
        $length = \strlen($chunk);
        if (!$this->acceptBody || $length === 0) {
            return $length;
        }

        if ($this->bodyWriteError !== null) {
            return 0;
        }

        if ($this->maxResponseBodyBytes > 0
            && $length
                > $this->maxResponseBodyBytes
                    - $this->receivedBodyBytes
        ) {
            $this->bodyWriteError = new \LengthException(
                'HTTP response body exceeded the maximum size of '
                    . "{$this->maxResponseBodyBytes} bytes."
            );

            return 0;
        }

        $body = $this->body();
        if ($body !== null) {
            if (!$body->append($chunk)) {
                $this->paused = true;
                $this->pausedChunkLength = $length;

                return CURL_WRITEFUNC_PAUSE;
            }
        } elseif ($this->buffersResponseBody()) {
            $this->bufferedBody .= $chunk;
        } else {
            return 0;
        }

        $this->receivedBodyBytes += $length;
        $this->recordReadActivity();

        return $length;
    }

    /**
     * Determine whether a write callback buffers the complete response.
     */
    public function buffersResponseBody(): bool
    {
        return !$this->isStreaming() && $this->maxResponseBodyBytes > 0;
    }

    /**
     * Get body bytes collected by the size-limiting write callback.
     */
    public function bufferedBody(): string
    {
        return $this->bufferedBody;
    }

    /**
     * Complete response trailers after a successful transfer.
     *
     * @return void
     */
    public function completeTrailers(): void
    {
        $this->trailers->complete($this->trailerHeaders);
    }

    /**
     * Fail response trailers after an unsuccessful transfer.
     *
     * @return void
     */
    public function failTrailers(\Throwable $failure): void
    {
        $this->trailers->fail($failure);
    }

    /**
     * Append one field while preserving its first observed casing.
     *
     * @param array<string, list<string>> $headers
     *
     * @return void
     */
    private function appendHeader(
        array &$headers,
        string $name,
        string $value
    ): void
    {
        foreach ($headers as $headerName => $values) {
            if (\strcasecmp($headerName, $name) === 0) {
                $headers[$headerName][] = $value;

                return;
            }
        }

        $headers[$name] = [$value];
    }

    /**
     * Remove encoded representation metadata after cURL decodes the body.
     *
     * @return void
     */
    private function normalizeDecodedHeaders(): void
    {
        if (!$this->decodeContent
            || !$this->responseBodyAllowed
            || !$this->statusAllowsResponseBody()
            || !$this->hasNonIdentityContentEncoding()
        ) {
            return;
        }

        $this->removeHeader($this->headers, 'Content-Encoding');
        $this->removeHeader($this->headers, 'Content-Length');
    }

    /**
     * Determine whether the response status permits body content.
     */
    private function statusAllowsResponseBody(): bool
    {
        return !(
            ($this->status >= 100 && $this->status < 200)
            || $this->status === 204
            || $this->status === 205
            || $this->status === 304
        );
    }

    /**
     * Determine whether the response declares a decoded content coding.
     */
    private function hasNonIdentityContentEncoding(): bool
    {
        foreach ($this->headers as $name => $values) {
            if (\strcasecmp($name, 'Content-Encoding') !== 0) {
                continue;
            }

            foreach ($values as $value) {
                foreach (\explode(',', $value) as $coding) {
                    $coding = \trim($coding);
                    if ($coding !== ''
                        && \strcasecmp(
                            $coding,
                            'identity'
                        ) !== 0
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Remove a header by lowercase field name.
     *
     * @param array<string, list<string>> $headers
     *
     * @return void
     */
    private function removeHeader(array &$headers, string $name): void
    {
        foreach (\array_keys($headers) as $headerName) {
            if (\strcasecmp($headerName, $name) === 0) {
                unset($headers[$headerName]);
            }
        }
    }

    /**
     * Get an unambiguous final Content-Length value.
     */
    private function responseContentLength(): ?int
    {
        foreach ($this->headers as $name => $values) {
            if (\strcasecmp($name, 'Content-Length') !== 0) {
                continue;
            }

            if (\count($values) !== 1) {
                return null;
            }

            $value = \trim($values[0]);
            if (\preg_match('/^(?:0|[1-9][0-9]*)$/', $value) !== 1
            ) {
                return null;
            }

            $maximum = (string) \PHP_INT_MAX;
            if (\strlen($value) > \strlen($maximum)
                || (
                    \strlen($value) === \strlen($maximum)
                    && \strcmp($value, $maximum) > 0
                )
            ) {
                return null;
            }

            return (int) $value;
        }

        return null;
    }

    /**
     * Determine whether the current header block precedes another response.
     */
    private function isInterimResponse(): bool
    {
        if ($this->currentStatus >= 100 && $this->currentStatus < 200) {
            return $this->currentStatus !== 101;
        }

        if (!$this->proxyConnectResponseIgnored
            && $this->ignoreProxyConnectResponse
            && $this->currentStatus >= 200
            && $this->currentStatus < 300
        ) {
            $this->proxyConnectResponseIgnored = true;

            return true;
        }

        return false;
    }
}
