<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client;

use Psr\Http\Message\RequestInterface;
use Smithy\Async\EventLoopInterface;
use Smithy\Async\RevoltEventLoop;
use Smithy\Http\Client\Cancellation\CancellationInterface;
use Smithy\Http\Client\Cancellation\CancelledException;
use Smithy\Http\Client\Connection\ConnectionManagerInterface;
use Smithy\Http\Client\Connection\HttpVersion;
use Smithy\Http\Client\Configuration\ConnectConfiguration;
use Smithy\Http\Client\Configuration\HTTPVersionPolicy;
use Smithy\Http\Client\Connection\RequestContext;
use Smithy\Http\Client\Curl\{
    CurlConnection,
    CurlConnectionManager,
    CurlResponseState,
    CurlTransferException,
    PendingRequest
};
use Smithy\Http\Client\Exception\ConnectionAcquireTimeoutException;
use Smithy\Http\Client\Exception\HttpException;
use Smithy\Http\Client\Exception\NetworkException;
use Smithy\Http\Client\Exception\RequestCancelledException;
use Smithy\Http\Client\Exception\RequestException;
use Smithy\Http\Message\AsyncRequestStreamInterface;
use Smithy\Http\Message\RequestStreamInterface;
use Smithy\Http\Message\ResourceRequestStream;
use Smithy\Http\Message\Response;
use Smithy\Http\Message\StreamingResponseStream;
use Smithy\Http\Message\ResponseInterface;
use Smithy\Http\Message\StreamBodyAdapter;
use Smithy\Http\Message\StringResponseStream;

/**
 * Send HTTP requests through cURL.
 *
 * Uses one shared curl_multi handle and suspends fibers during I/O.
 */
final class CurlClient implements HttpClientInterface
{
    private const MIN_POLL_INTERVAL = 0.001;
    private const MAX_POLL_INTERVAL = 0.01;
    private const POLL_BACKOFF_FACTOR = 2.0;
    private const PRODUCTIVE_POLL_BUDGET_NS = 1_000_000;
    private const MAX_PRODUCTIVE_POLLS = 64;
    private const SCAN_DOWNLOAD_PROGRESS = 1;
    private const SCAN_UPLOAD_PROGRESS = 2;
    private const SCAN_PRETRANSFER = 4;
    private const MAX_CACHED_REQUEST_CONTEXTS = 64;

    private readonly EventLoopInterface $eventLoop;
    private readonly HttpRequestOptions $defaultOptions;
    private readonly ConnectionManagerInterface $connectionManager;
    private readonly ConnectConfiguration $connectConfig;
    private \CurlMultiHandle $multiHandle;

    /** @var array<string, RequestContext> Contexts by destination and proxy. */
    private array $requestContexts = [];

    /** @var array<int, PendingRequest> */
    private array $pending = [];

    /** @var array<int, int> */
    private array $progressScanMasks = [];

    /** @var array<int, true> Handles whose streamed response awaits delivery. */
    private array $streamReady = [];
    private bool $callbackActivityObserved = false;
    private bool $pollScheduled = false;
    private bool $polling = false;
    private bool $pollImmediately = false;
    private int $productivePolls = 0;
    private ?int $productivePollStartedAt = null;
    private ?string $pollTimerId = null;
    private float $pollInterval = self::MIN_POLL_INTERVAL;
    private ?string $timeoutTimerId = null;
    private ?float $timeoutDeadline = null;
    private bool $closed = false;

    /**
     * @throws \RuntimeException If cURL or required options are unavailable.
     */
    public function __construct(
        ?EventLoopInterface $eventLoop = null,
        ?ConnectionManagerInterface $connectionManager = null,
        ?ConnectConfiguration $connectConfiguration = null,
        ?HttpRequestOptions $defaultOptions = null,
        private readonly bool $preservePath = false
    )
    {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException(
                'The cURL PHP extension is required.'
            );
        }

        if ($preservePath && !\defined('CURLOPT_PATH_AS_IS')) {
            throw new \RuntimeException(
                'This cURL build does not support preserving request paths.'
            );
        }

        $this->eventLoop = $eventLoop ?? new RevoltEventLoop();
        $this->defaultOptions = $defaultOptions ?? new HttpRequestOptions();

        $this->connectionManager = $connectionManager
            ?? new CurlConnectionManager(eventLoop: $this->eventLoop);
        $this->connectConfig = $connectConfiguration
            ?? new ConnectConfiguration();
        $this->multiHandle = \curl_multi_init();
        $multiOptions = [];
        if (\defined('CURLMOPT_PIPELINING')
            && \defined('CURLPIPE_MULTIPLEX')
        ) {
            $multiOptions[\constant('CURLMOPT_PIPELINING')] =
                \constant('CURLPIPE_MULTIPLEX');
        }

        if (!\defined('CURLMOPT_MAX_CONCURRENT_STREAMS')
            && $this->connectConfig->h2Configuration !== null
        ) {
            throw new \RuntimeException(
                'This cURL build does not support HTTP/2 stream limits.'
            );
        }

        if (\defined('CURLMOPT_MAX_CONCURRENT_STREAMS')) {
            $multiOptions[\constant('CURLMOPT_MAX_CONCURRENT_STREAMS')] =
                $this->connectConfig->h2Configuration->maxStreams ?? 100;
        }

        if ($this->connectionManager instanceof CurlConnectionManager) {
            $configuration = $this->connectionManager
                ->getConfiguration();
            if (\defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
                $multiOptions[\constant('CURLMOPT_MAX_HOST_CONNECTIONS')] =
                    $configuration->maxConcurrency;
            }

            if (\defined('CURLMOPT_MAXCONNECTS')
                && $configuration->maxIdleTotal > 0
            ) {
                $multiOptions[\constant('CURLMOPT_MAXCONNECTS')] =
                    $configuration->maxIdleTotal;
            }
        }

        foreach ($multiOptions as $option => $value) {
            if (!\curl_multi_setopt(
                $this->multiHandle,
                $option,
                $value
            )) {
                throw new \RuntimeException(
                    "Unable to configure cURL multi option {$option}."
                );
            }
        }
    }

    /**
     * Send a request via curl_multi with fiber suspension.
     *
     * The fiber suspends during I/O, allowing other fibers to
     * make progress on the shared curl_multi handle.
     *
     * @throws \InvalidArgumentException If request trailers are configured.
     */
    public function sendRequest(
        RequestInterface $request,
        ?HttpRequestOptions $options = null
    ): ResponseInterface
    {
        if ($this->closed) {
            $body = $request->getBody() instanceof RequestStreamInterface
                ? $request->getBody()
                : null;

            throw new NetworkException(
                'HTTP client is closed.',
                request: $request,
                requestBodyStarted: $body?->started() ?? false,
                requestBodyFullyConsumed: $body?->fullyConsumed() ?? false,
                requestBodySeekable: $body?->isSeekable()
            );
        }

        $options ??= $this->defaultOptions;
        $this->validateRequestOptions($request, $options);
        $this->throwIfCancelled(
            $request,
            $options->cancellation
        );
        $uri = $request->getUri();
        $url = (string) $uri;
        $requestMethod = $request->getMethod();
        if ($url === '' || $requestMethod === '') {
            throw new RequestException(
                'HTTP requests need a method and a URI.',
                $request
            );
        }
        $proxy = $options->proxy;
        $contextKey = $uri->getScheme() . '://' . $uri->getHost() . ':'
            . $uri->getPort() . ($proxy === null ? '' : '|' . $proxy);
        $requestContext = $this->requestContexts[$contextKey] ?? null;
        if ($requestContext === null) {
            // shortcut: reset at a fixed size, use an LRU if hosts churn.
            if (\count($this->requestContexts)
                >= self::MAX_CACHED_REQUEST_CONTEXTS
            ) {
                $this->requestContexts = [];
            }

            $requestContext = RequestContext::fromUri($uri, $proxy);
            $this->requestContexts[$contextKey] = $requestContext;
        }

        try {
            $exchange = $this->connectionManager->lease(
                $requestContext,
                $this->connectConfig,
                $options->cancellation
            );
        } catch (CancelledException $error) {
            throw $this->createCancellationException(
                $request,
                null,
                $error
            );
        } catch (ConnectionAcquireTimeoutException $error) {
            throw new NetworkException(
                $error->getMessage(),
                $request,
                $error
            );
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw $this->createTransportException(
                'HTTP connection acquisition failed.',
                $request,
                $error
            );
        }

        if (!$exchange instanceof CurlConnection) {
            $releaseError = null;

            try {
                $this->connectionManager->release($exchange);
            } catch (\Throwable $error) {
                $releaseError = $error;
            }

            throw new \LogicException(
                'CurlClient requires CurlConnection exchanges.',
                0,
                $releaseError
            );
        }

        $handle = $exchange->getHandle();
        $transferOwned = false;
        $uploadState = null;
        $transferError = null;

        try {
            $requestBody = $request->getBody();
            $requestBodySize = $requestBody->getSize();
            $hasTransferEncoding =
                $request->hasHeader('Transfer-Encoding');
            $responseBodyAllowed =
                \strcasecmp($requestMethod, 'HEAD') !== 0;
            $bufferedDownloadBufferSize =
                $options->bufferedDownloadBufferSize;
            $bufferedDownload =
                $requestBodySize === 0
                && !$hasTransferEncoding;
            $streamedResponseBufferSize = \min(
                $options->responseBufferSize,
                512 * 1024
            );

            if ($bufferedDownload
                && $bufferedDownloadBufferSize > 0
            ) {
                \curl_setopt(
                    $handle,
                    CURLOPT_BUFFERSIZE,
                    $bufferedDownloadBufferSize
                );
            } else {
                \curl_setopt(
                    $handle,
                    CURLOPT_BUFFERSIZE,
                    $streamedResponseBufferSize
                );
            }

            $responseBody = null;
            $responseState = new CurlResponseState(
                $responseBody,
                $options->decodeContent,
                $options->maxResponseBodyBytes,
                $responseBodyAllowed,
                function () use ($handle): void {
                    $pending =
                        $this->pending[self::handleId($handle)] ?? null;
                    if ($pending !== null) {
                        $this->scheduleTimeoutForPending(
                            $pending
                        );
                    }
                },
                maxBufferedResponseBytes: $options
                    ->maxBufferedResponseBytes,
                ignoreProxyConnectResponse: $options->proxy !== null
                    && \strtolower($request->getUri()->getScheme())
                        === 'https'
            );
            $forbidReuse = false;
            if ($this->connectionManager instanceof CurlConnectionManager) {
                $connectionManagerConfig = $this->connectionManager
                    ->getConfiguration();
                $forbidReuse = $connectionManagerConfig->maxIdleTotal === 0
                    || $connectionManagerConfig->maxIdlePerHost === 0;
            }
            $curlOptions = [
                CURLOPT_URL => $url,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CUSTOMREQUEST => $requestMethod,
                CURLOPT_NOBODY => \strcasecmp($requestMethod, 'HEAD') === 0,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT_MS =>
                    self::timeoutToCurlMilliseconds($options->timeout),
                CURLOPT_HTTP_CONTENT_DECODING =>
                    $options->decodeContent,
                CURLOPT_FORBID_REUSE => $forbidReuse
            ];

            if ($this->preservePath) {
                $curlOptions[\constant('CURLOPT_PATH_AS_IS')] = true;
            }

            if (\defined('CURLOPT_PIPEWAIT')) {
                $curlOptions[\constant('CURLOPT_PIPEWAIT')] =
                    $this->connectConfig->versionPolicy
                        !== HTTPVersionPolicy::EnforceHTTP_1_1;
            }

            \curl_setopt_array($handle, $curlOptions);

            $acceptEncoding = $request->getHeaderLine(
                'Accept-Encoding'
            );
            if ($options->decodeContent) {
                if ($request->hasHeader('Accept-Encoding')
                    && $acceptEncoding === ''
                ) {
                    $acceptEncoding = 'identity';
                }

                \curl_setopt(
                    $handle,
                    CURLOPT_ENCODING,
                    $acceptEncoding
                );
            } elseif ($acceptEncoding === '') {
                \curl_setopt(
                    $handle,
                    CURLOPT_ENCODING,
                    'identity'
                );
            }

            if ($options->proxy !== null) {
                \curl_setopt(
                    $handle,
                    CURLOPT_PROXY,
                    $options->proxy
                );
            }

            $uploadBody = StreamBodyAdapter::wrap(
                $requestBody
            );
            $contentLengthValues =
                $request->getHeader('Content-Length');
            if ($contentLengthValues !== []) {
                if (\count($contentLengthValues) !== 1
                    || !\ctype_digit($contentLengthValues[0])
                ) {
                    throw new RequestException(
                        'Content-Length must contain one '
                            . 'non-negative integer.',
                        $request
                    );
                }

                $value = \ltrim($contentLengthValues[0], '0');
                if ($value === '') {
                    $declaredLength = 0;
                } else {
                    $maximum = (string) \PHP_INT_MAX;
                    if (\strlen($value) > \strlen($maximum)
                        || (
                            \strlen($value)
                                === \strlen($maximum)
                            && \strcmp($value, $maximum) > 0
                        )
                    ) {
                        throw new RequestException(
                            'Content-Length exceeds the supported '
                                . 'integer range.',
                            $request
                        );
                    }

                    $declaredLength = (int) $value;
                }
            } else {
                $declaredLength = null;
            }

            if ($declaredLength !== null
                && $hasTransferEncoding
            ) {
                throw new RequestException(
                    'Content-Length and Transfer-Encoding cannot '
                        . 'both be set.',
                    $request
                );
            }

            $bodyLength = $uploadBody->length();
            if ($declaredLength !== null
                && $bodyLength !== null
                && $declaredLength !== $bodyLength
            ) {
                throw new RequestException(
                    "Content-Length {$declaredLength} does not match "
                        . "the request body length {$bodyLength}.",
                    $request
                );
            }

            if ($declaredLength === 0
                && $bodyLength === null
                && !$uploadBody->eof()
            ) {
                $probe = $uploadBody->read(1);
                if ($probe !== '') {
                    throw new RequestException(
                        'Content-Length 0 does not match the '
                            . 'non-empty request body.',
                        $request
                    );
                }

                if (!$uploadBody->eof()) {
                    throw new \RuntimeException(
                        'Request body returned no data before '
                            . 'reaching EOF.'
                    );
                }
            }

            $length = $declaredLength ?? $bodyLength;
            if ($length === 0
                && $uploadBody->eof()
                && !$hasTransferEncoding
            ) {
                $uploadBody = null;
            }

            $curlHeaders = [];
            foreach ($request->getHeaders()
                as $name => $values
            ) {
                if ($options->decodeContent
                    && \strcasecmp(
                        $name,
                        'Accept-Encoding'
                    ) === 0
                ) {
                    continue;
                }

                foreach ($values as $value) {
                    $curlHeaders[] = "{$name}: {$value}";
                }
            }
            \curl_setopt(
                $handle,
                CURLOPT_HTTPHEADER,
                $curlHeaders
            );

            if ($uploadBody !== null) {
                $uploadLength =
                    $hasTransferEncoding
                        ? null
                        : ($declaredLength ?? $bodyLength);
                $uploadState = new UploadState(
                    $uploadBody,
                    $uploadLength
                );
                $uploadOptions = [CURLOPT_UPLOAD => true];
                if (\defined('CURLOPT_UPLOAD_BUFFERSIZE')) {
                    $uploadOptions[\constant('CURLOPT_UPLOAD_BUFFERSIZE')] =
                        $options->uploadBufferSize;
                }
                \curl_setopt_array($handle, $uploadOptions);

                if ($uploadState->body
                        instanceof AsyncRequestStreamInterface
                ) {
                    $uploadState->readinessSubscription =
                        $uploadState->body->subscribe(
                            function () use ($handle): void {
                                $handleId = self::handleId($handle);
                                $pending =
                                    $this->pending[$handleId]
                                        ?? null;
                                $uploadState =
                                    $pending?->uploadState;

                                if ($uploadState === null
                                    || !$uploadState->paused
                                    || $uploadState
                                        ->resumeScheduled
                                ) {
                                    return;
                                }

                                $uploadState->resumeScheduled =
                                    true;
                                $this->eventLoop->defer(
                                    function () use (
                                        $handleId
                                    ): void
                                    {
                                        $pending =
                                            $this->pending[
                                                $handleId
                                            ] ?? null;
                                        $uploadState =
                                            $pending?->uploadState;

                                        if ($uploadState === null) {
                                            return;
                                        }

                                        $uploadState
                                            ->resumeScheduled =
                                                false;
                                        if (!$uploadState->paused) {
                                            return;
                                        }

                                        $uploadState->paused =
                                            false;
                                        $pause = 0;
                                        if ($pending
                                                ->responseState
                                                ->paused
                                        ) {
                                            $pause |=
                                                CURLPAUSE_RECV;
                                        }
                                        $result = \curl_pause(
                                            $pending->getHandle(),
                                            $pause
                                        );
                                        if ($result === CURLE_OK) {
                                            return;
                                        }

                                        $readError =
                                            new \RuntimeException(
                                                'Unable to resume '
                                                    . 'request '
                                                    . 'body: '
                                                    . \curl_strerror(
                                                        $result
                                                    )
                                            );
                                        $uploadState->readError =
                                            $readError;
                                        $taken = $this
                                            ->takePendingRequest(
                                                $handleId
                                            );
                                        if ($taken !== null) {
                                            $this
                                                ->failUploadRead(
                                                    $taken,
                                                    $uploadState,
                                                    $readError
                                                );
                                        }
                                    }
                                );
                            }
                        );
                }

                if ($uploadState->expectedLength !== null) {
                    \curl_setopt(
                        $handle,
                        \defined('CURLOPT_INFILESIZE_LARGE')
                            ? \constant(
                                'CURLOPT_INFILESIZE_LARGE'
                            )
                            : \CURLOPT_INFILESIZE,
                        $uploadState->expectedLength
                    );
                }

                if (self::canUseNativeReadData($uploadState->body)) {
                    \curl_setopt(
                        $handle,
                        CURLOPT_READDATA,
                        $uploadState->body->getResource()
                    );
                } else {
                    \curl_setopt(
                        $handle,
                        CURLOPT_READFUNCTION,
                        function (
                            $ch,
                            $fd,
                            int $length
                        ) use (
                            $uploadState,
                            $responseState
                        ): string|int
                        {
                            try {
                                if ($uploadState->readError
                                        !== null
                                ) {
                                    return '';
                                }

                                if ($uploadState->body
                                        instanceof
                                            AsyncRequestStreamInterface
                                    && !$uploadState->body->isReady()
                                ) {
                                    $uploadState->paused = true;

                                    return CURL_READFUNC_PAUSE;
                                }

                                if ($uploadState->retainedFinalChunk
                                        !== null
                                ) {
                                    self::verifyUploadBodyExhausted(
                                        $uploadState
                                    );
                                    $chunk = $uploadState
                                        ->retainedFinalChunk;

                                    if (\strlen($chunk) > $length
                                    ) {
                                        $uploadState
                                            ->retainedFinalChunk =
                                                \substr(
                                                    $chunk,
                                                    $length
                                                );
                                        $chunk = \substr(
                                            $chunk,
                                            0,
                                            $length
                                        );
                                    } else {
                                        $uploadState
                                            ->retainedFinalChunk =
                                                null;
                                    }

                                    $uploadState->bytesRead +=
                                        \strlen($chunk);
                                    $result = $chunk;
                                } else {
                                    $remaining =
                                        $uploadState
                                            ->expectedLength === null
                                                ? null
                                                : $uploadState
                                                    ->expectedLength
                                                    - $uploadState
                                                        ->bytesRead;

                                    if ($remaining !== null) {
                                        if ($remaining <= 0) {
                                            return '';
                                        }

                                        $length = \min(
                                            $length,
                                            $remaining
                                        );
                                    }

                                    $chunk = $uploadState
                                        ->body
                                        ->read($length);
                                    $chunkLength =
                                        \strlen($chunk);

                                    if ($chunkLength > $length
                                    ) {
                                        throw new
                                            \UnexpectedValueException(
                                                'Request body returned '
                                                    . "{$chunkLength} "
                                                    . 'bytes when at most '
                                                    . "{$length} were "
                                                    . 'requested.'
                                            );
                                    }

                                    if ($chunk === '') {
                                        if ($uploadState->body
                                                instanceof
                                                    AsyncRequestStreamInterface
                                            && !$uploadState
                                                ->body
                                                ->isReady()
                                        ) {
                                            $uploadState->paused =
                                                true;

                                            return
                                                CURL_READFUNC_PAUSE;
                                        }

                                        if (!$uploadState->body
                                                ->eof()
                                        ) {
                                            throw new
                                                \RuntimeException(
                                                    'Request body '
                                                        . 'returned no '
                                                        . 'data before '
                                                        . 'reaching EOF.'
                                                );
                                        }

                                        if ($uploadState
                                                ->expectedLength
                                                    !== null
                                            && $uploadState
                                                ->bytesRead
                                                    < $uploadState
                                                        ->expectedLength
                                        ) {
                                            $bytesRead =
                                                $uploadState->bytesRead;
                                            $expectedLength =
                                                $uploadState->expectedLength;

                                            throw new
                                                \UnexpectedValueException(
                                                    'Request body ended '
                                                        . 'after '
                                                        . "{$bytesRead} "
                                                        . 'bytes; expected '
                                                        . "{$expectedLength}."
                                                );
                                        }

                                        return '';
                                    }

                                    $nextBytesRead =
                                        $uploadState->bytesRead
                                            + $chunkLength;

                                    if ($uploadState
                                            ->expectedLength
                                                !== null
                                        && $nextBytesRead
                                            === $uploadState
                                                ->expectedLength
                                    ) {
                                        if ($uploadState->body
                                                instanceof
                                                    AsyncRequestStreamInterface
                                            && !$uploadState
                                                ->body
                                                ->isReady()
                                        ) {
                                            $uploadState
                                                ->retainedFinalChunk =
                                                    $chunk;
                                            $uploadState->paused =
                                                true;

                                            return
                                                CURL_READFUNC_PAUSE;
                                        }

                                        self::verifyUploadBodyExhausted(
                                            $uploadState
                                        );
                                    }

                                    $uploadState->bytesRead =
                                        $nextBytesRead;
                                    $result = $chunk;
                                }

                                if ($result !== '') {
                                    $this->callbackActivityObserved =
                                        true;
                                }

                                if (!$responseState
                                        ->isReadTimeoutStarted()
                                    && $uploadState->body
                                        ->fullyConsumed()
                                ) {
                                    $responseState
                                        ->recordReadActivity();
                                }

                                return $result;
                            } catch (\Throwable $error) {
                                $uploadState->readError = $error;

                                return '';
                            }
                        }
                    );
                }
            }
            $handleId = self::handleId($handle);
            $writeCallback = function (
                $ch,
                string $chunk
            ) use ($responseState): int
            {
                try {
                    if ($chunk !== '') {
                        $this->callbackActivityObserved = true;
                    }

                    return $responseState->consumeBody($chunk);
                } catch (\Throwable $error) {
                    $responseState->bodyWriteError ??= $error;

                    return 0;
                }
            };
            \curl_setopt(
                $handle,
                CURLOPT_HEADERFUNCTION,
                function ($ch, string $header) use (
                    $handleId,
                    $options,
                    $responseState,
                    $writeCallback
                ): int
                {
                    $length = \strlen($header);
                    $this->callbackActivityObserved = true;

                    try {
                        if ($responseState->consumeHeader($header)
                            && $responseState->shouldStreamResponseBody()
                            && !$responseState->isStreaming()
                        ) {
                            if (!\curl_setopt(
                                    $ch,
                                    CURLOPT_TIMEOUT_MS,
                                    0
                                )
                                || !\curl_setopt(
                                    $ch,
                                    CURLOPT_RETURNTRANSFER,
                                    false
                                )
                                || !\curl_setopt(
                                    $ch,
                                    CURLOPT_WRITEFUNCTION,
                                    $writeCallback
                                )
                            ) {
                                throw new \RuntimeException(
                                    'Unable to configure streamed '
                                        . 'response body.'
                                );
                            }

                            $responseState
                                ->activateStreamingBody(
                                    $this
                                        ->createStreamingResponseStream(
                                            $handleId,
                                            $options
                                        )
                                );
                            $this->streamReady[$handleId] = true;
                            $scanMask =
                                $this->progressScanMasks[$handleId]
                                    ?? 0;
                            $scanMask &=
                                ~self::SCAN_DOWNLOAD_PROGRESS;
                            if ($scanMask === 0) {
                                unset(
                                    $this
                                        ->progressScanMasks[
                                            $handleId
                                        ]
                                );
                            } else {
                                $this
                                    ->progressScanMasks[$handleId] =
                                        $scanMask;
                            }
                        }
                    } catch (\Throwable $error) {
                        $responseState->bodyWriteError ??= $error;

                        return 0;
                    }

                    return $length;
                }
            );

            if ($responseState->requiresWriteCallback()) {
                \curl_setopt(
                    $handle,
                    CURLOPT_WRITEFUNCTION,
                    $writeCallback
                );
            }

            $multiResult = \curl_multi_add_handle(
                $this->multiHandle,
                $handle
            );
            if ($multiResult !== CURLM_OK) {
                throw new NetworkException(
                    "Unable to add cURL handle: "
                        . \curl_multi_strerror($multiResult),
                    $request
                );
            }
            $transferOwned = true;

            $result = null;
            $cancellation = $options->cancellation;
            // Hand the result back by reference, not through resume().
            // Revolt keeps the last {main} resume value alive in its loop
            // fiber, which would pin the response and its exchange.
            $this->eventLoop->suspend(
                function (
                    callable $resume,
                    callable $throw
                ) use (
                    $exchange,
                    $handleId,
                    $request,
                    $uploadState,
                    $responseState,
                    $cancellation,
                    $options,
                    $requestBodySize,
                    &$result
                ): void
                {
                    $wasIdle = $this->pending === [];
                    $pending = new PendingRequest(
                        exchange: $exchange,
                        request: $request,
                        resume: static function (
                            mixed $value
                        ) use (
                            $resume,
                            &$result
                        ): void
                        {
                            $result = $value;
                            $resume();
                        },
                        throw: $throw,
                        uploadState: $uploadState,
                        responseState: $responseState,
                        requestBodySize: $requestBodySize,
                        cancellation: $cancellation,
                        options: $options
                    );
                    $this->pending[$handleId] = $pending;
                    $scanMask = 0;
                    if (!$responseState->isStreaming()
                        && !$responseState->buffersResponseBody()
                    ) {
                        $scanMask |= self::SCAN_DOWNLOAD_PROGRESS;
                    }

                    if ($uploadState !== null
                        && self::canUseNativeReadData($uploadState->body)
                    ) {
                        $scanMask |= self::SCAN_UPLOAD_PROGRESS;
                    } elseif ($uploadState === null) {
                        if ($requestBodySize !== null
                            && $requestBodySize > 0
                        ) {
                            $scanMask |=
                                self::SCAN_UPLOAD_PROGRESS;
                        } else {
                            $scanMask |= self::SCAN_PRETRANSFER;
                        }
                    }

                    if ($scanMask !== 0) {
                        $this->progressScanMasks[$handleId] =
                            $scanMask;
                    }

                    if ($cancellation !== null) {
                        $pending->cancellationSubscription =
                            $cancellation->subscribe(
                                function (
                                    CancelledException $error
                                ) use (
                                    $pending,
                                    $handleId
                                ): void
                                {
                                    $pending->cancellationReason =
                                        $error;
                                    $this->eventLoop->defer(
                                        function () use (
                                            $handleId
                                        ): void
                                        {
                                            $pending = $this
                                                ->takePendingRequest(
                                                    $handleId
                                                );
                                            if ($pending === null
                                                || $pending
                                                    ->cancellationReason
                                                    === null
                                            ) {
                                                return;
                                            }

                                            $cancellationReason = $pending
                                                ->cancellationReason;
                                            $exception = $this
                                                ->createCancellationException(
                                                    $pending->request,
                                                    $pending->uploadState,
                                                    $cancellationReason
                                                );
                                            $this
                                                ->failPendingRequest(
                                                    $pending,
                                                    $exception
                                                );
                                        }
                                    );
                                }
                            );
                    }

                    $this->scheduleTimeoutForPending($pending);

                    if ($wasIdle) {
                        $this->pollInterval =
                            self::MIN_POLL_INTERVAL;
                    }

                    $this->startPolling(true);
                }
            );

            if (!\is_string($result)
                && !$result instanceof ResponseInterface
            ) {
                throw new \LogicException(
                    'HTTP transfer resumed without a response.'
                );
            }

            if ($result instanceof ResponseInterface) {
                return $result;
            }

            if ($responseState->headerError !== null) {
                throw $responseState->headerError;
            }

            return Response::fromNormalized(
                $responseState->status,
                $responseState->headers,
                $result === ''
                    ? StringResponseStream::empty()
                    : new StringResponseStream($result),
                $responseState->protocolVersion,
                $responseState->reason,
                $responseState->trailers()
            );
        } catch (\Throwable $error) {
            $transferError = $error;

            throw $error;
        } finally {
            if (!$transferOwned) {
                $cleanupError = $this->cleanupExchange(
                    $exchange,
                    $request,
                    $uploadState
                );

                if ($transferError === null
                    && $cleanupError !== null
                ) {
                    throw $cleanupError;
                }
            }
        }
    }

    /**
     * Get the connection manager.
     */
    public function getConnectionManager(): ConnectionManagerInterface
    {
        return $this->connectionManager;
    }

    /**
     * Access the event loop.
     */
    public function getEventLoop(): EventLoopInterface
    {
        return $this->eventLoop;
    }

    /**
     * Return default request options.
     */
    public function getDefaultRequestOptions(): HttpRequestOptions
    {
        return $this->defaultOptions;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->cancelPollTimer();
        $this->cancelTimeoutTimer();

        foreach (\array_keys($this->pending) as $handleId) {
            $pending = $this->takePendingRequest($handleId);
            if ($pending !== null) {
                $uploadBody = $pending->uploadState?->body;
                $pending->exchange->markUnhealthy();
                $this->failPendingRequest(
                    $pending,
                    new NetworkException(
                        'HTTP client was closed.',
                        request: $pending->request,
                        requestBodyStarted:
                            $uploadBody?->started() ?? false,
                        requestBodyFullyConsumed:
                            $uploadBody?->fullyConsumed() ?? false,
                        requestBodySeekable:
                            $uploadBody?->isSeekable()
                    )
                );
            }
        }

        try {
            $this->connectionManager->clear();
        } catch (\Throwable) {
            // close() is also used by __destruct(), where throwing would be
            // fatal. Pending requests have already been failed above.
        }
        \curl_multi_close($this->multiHandle);
    }

    /**
     * Release active transfers and close the multi handle.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->close();
    }

    private function startPolling(
        bool $immediate = false,
        ?float $delay = null
    ): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->polling) {
            $this->pollImmediately = $this->pollImmediately
                || $immediate;

            return;
        }

        if ($this->pollScheduled) {
            if (!$immediate || $this->pollTimerId === null) {
                return;
            }

            $this->cancelPollTimer();
        }

        $this->pollScheduled = true;

        if ($immediate) {
            $this->eventLoop->defer(
                fn() => $this->runScheduledPoll()
            );

            return;
        }

        $this->pollTimerId = $this->eventLoop->schedule(
            $delay ?? $this->pollInterval,
            fn() => $this->runScheduledPoll()
        );
    }

    /**
     * Run one scheduled poll and arm the next iteration.
     *
     * @return void
     */
    private function runScheduledPoll(): void
    {
        $this->pollScheduled = false;
        $this->pollTimerId = null;

        if ($this->closed || $this->pending === []) {
            return;
        }

        $this->polling = true;
        $madeProgress = false;

        try {
            $madeProgress = $this->poll();
            $this->updatePollInterval($madeProgress);
        } finally {
            $this->polling = false;
            $this->scheduleNextPoll($madeProgress);
        }
    }

    /**
     * Update the adaptive poll interval.
     *
     * @return void
     */
    private function updatePollInterval(bool $madeProgress): void
    {
        $this->pollInterval = $madeProgress
            ? self::MIN_POLL_INTERVAL
            : \min(
                self::MAX_POLL_INTERVAL,
                $this->pollInterval
                    * self::POLL_BACKOFF_FACTOR
        );
    }

    /**
     * Continue productive polling while timer-driven work advances.
     *
     * @return void
     */
    private function scheduleNextPoll(bool $madeProgress): void
    {
        $immediate = $this->pollImmediately;
        $this->pollImmediately = false;

        if ($this->closed || $this->pending === []) {
            $this->resetProductivePolling();

            return;
        }

        if (!$madeProgress) {
            $this->resetProductivePolling();
            $this->startPolling($immediate);

            return;
        }

        $now = \hrtime(true);
        $this->productivePollStartedAt ??= $now;
        $this->productivePolls++;
        if ($this->productivePolls < self::MAX_PRODUCTIVE_POLLS
            && $now - $this->productivePollStartedAt
                < self::PRODUCTIVE_POLL_BUDGET_NS
        ) {
            $this->startPolling(true);

            return;
        }

        $this->resetProductivePolling();
        $this->startPolling(delay: 0.0);
    }

    /**
     * Reset productive polling state.
     *
     * @return void
     */
    private function resetProductivePolling(): void
    {
        $this->productivePolls = 0;
        $this->productivePollStartedAt = null;
    }

    /**
     * Cancel a delayed poll callback.
     *
     * @return void
     */
    private function cancelPollTimer(): void
    {
        if ($this->pollTimerId === null) {
            return;
        }

        $this->eventLoop->cancel($this->pollTimerId);
        $this->pollTimerId = null;
        $this->pollScheduled = false;
    }

    /**
     * Single poll iteration: drive curl_multi, check for completed
     * requests, resume their fibers.
     *
     * @return bool Whether transfer progress was observed.
     */
    private function poll(): bool
    {
        $this->callbackActivityObserved = false;

        do {
            $result = \curl_multi_exec($this->multiHandle, $active);
        } while ($result === CURLM_CALL_MULTI_PERFORM);

        if ($result !== CURLM_OK) {
            $message = \curl_multi_strerror($result);
            foreach (\array_keys($this->pending) as $handleId) {
                $pending = $this->takePendingRequest($handleId);
                if ($pending === null) {
                    continue;
                }

                $uploadBody = $pending->uploadState?->body;
                $this->failPendingRequest(
                    $pending,
                    new NetworkException(
                        "cURL multi error {$result}: {$message}",
                        request: $pending->request,
                        requestBodyStarted:
                            $uploadBody?->started() ?? false,
                        requestBodyFullyConsumed:
                            $uploadBody?->fullyConsumed() ?? false,
                        requestBodySeekable:
                            $uploadBody?->isSeekable()
                    )
                );
            }

            return true;
        }

        $madeProgress = $this->callbackActivityObserved;
        if ($this->deliverStreamedResponses()) {
            $madeProgress = true;
        }

        foreach ($this->progressScanMasks as $handleId => $scanMask) {
            $pending = $this->pending[$handleId] ?? null;
            if ($pending === null) {
                unset($this->progressScanMasks[$handleId]);

                continue;
            }

            $handle = $pending->getHandle();
            if (($scanMask & self::SCAN_DOWNLOAD_PROGRESS) !== 0) {
                $downloaded = self::downloadedBytes($handle);

                if ($downloaded > $pending->downloadedBytes) {
                    $madeProgress = true;
                    $pending->responseState->recordReadActivity();
                    $pending->downloadedBytes = $downloaded;
                }
            }

            $uploaded = $pending->uploadedBytes;
            if (($scanMask & self::SCAN_UPLOAD_PROGRESS) !== 0) {
                $uploaded = self::uploadedBytes($handle);

                if ($uploaded > $pending->uploadedBytes) {
                    $madeProgress = true;
                    $pending->uploadedBytes = $uploaded;
                    $body = $pending->uploadState?->body;
                    if ($body instanceof ResourceRequestStream) {
                        $body->recordTransportRead($uploaded);
                    }
                }
            }

            $responseState = $pending->responseState;
            if (!$responseState->isReadTimeoutStarted()
                && ($scanMask & self::SCAN_UPLOAD_PROGRESS) !== 0
            ) {
                if ($pending->uploadState !== null) {
                    $uploadCompleted =
                        $pending->uploadState->body->fullyConsumed();
                } else {
                    $bodySize = $pending->requestBodySize;
                    $uploadCompleted = $bodySize !== null
                        && $bodySize > 0
                        && $uploaded >= $bodySize;
                }

                if ($uploadCompleted) {
                    $responseState->recordReadActivity();
                }
            }

            if (($scanMask & self::SCAN_PRETRANSFER) !== 0) {
                $readTimeoutStarted =
                    $responseState->isReadTimeoutStarted();
                if ($readTimeoutStarted
                    || \curl_getinfo(
                        $handle,
                        CURLINFO_PRETRANSFER_TIME
                    ) > 0.0
                ) {
                    if (!$readTimeoutStarted) {
                        $responseState->recordReadActivity();
                    }

                    $scanMask &= ~self::SCAN_PRETRANSFER;
                    if ($scanMask === 0) {
                        unset($this->progressScanMasks[$handleId]);

                        continue;
                    }

                    $this->progressScanMasks[$handleId] = $scanMask;
                }
            }
        }

        while ($info = \curl_multi_info_read($this->multiHandle)) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }

            /** @var \CurlHandle $completedHandle */
            $completedHandle = $info['handle'];
            $handleId = self::handleId($completedHandle);

            $pending = $this->takePendingRequest($handleId);
            if ($pending === null) {
                continue;
            }

            $madeProgress = true;

            if ($pending->cancellationReason !== null) {
                $this->failPendingRequest(
                    $pending,
                    $this->createCancellationException(
                        $pending->request,
                        $pending->uploadState,
                        $pending->cancellationReason
                    )
                );

                continue;
            }

            $uploadState = $pending->uploadState;
            $uploadReadError = $uploadState?->readError;
            if ($uploadReadError !== null) {
                $this->failUploadRead(
                    $pending,
                    $uploadState,
                    $uploadReadError
                );

                continue;
            }

            $responseState = $pending->responseState;
            $bodyWriteError = $responseState->bodyWriteError;
            if ($bodyWriteError !== null) {
                $this->failPendingRequest(
                    $pending,
                    $bodyWriteError instanceof HttpException
                        ? $bodyWriteError
                        : $this->createBodyWriteException(
                            $pending,
                            $bodyWriteError
                        )
                );

                continue;
            }

            if ($info['result'] !== CURLE_OK) {
                if ($info['result'] === CURLE_COULDNT_CONNECT) {
                    $connectionFailure = true;
                } elseif ($info['result'] === CURLE_OPERATION_TIMEDOUT) {
                    $connectionFailure =
                        !$pending->responseState->headersComplete
                        && !($pending->uploadState?->body->started()
                            ?? false)
                        && self::connectTimeIsZero($completedHandle);
                } else {
                    $connectionFailure = false;
                }

                $uploadBody = $pending->uploadState?->body;
                $this->failPendingRequest(
                    $pending,
                    new CurlTransferException(
                        "cURL error {$info['result']}: "
                            . \curl_error($completedHandle),
                        request: $pending->request,
                        curlCode: $info['result'],
                        connectionFailure: $connectionFailure,
                        requestBodyStarted:
                            $uploadBody?->started() ?? false,
                        requestBodyFullyConsumed:
                            $uploadBody?->fullyConsumed() ?? false,
                        requestBodySeekable:
                            $uploadBody?->isSeekable()
                    )
                );

                continue;
            }

            if (!$responseState->headersComplete) {
                $this->failPendingRequest(
                    $pending,
                    new NetworkException(
                        'HTTP response ended before final headers '
                            . 'were received.',
                        $pending->request
                    )
                );

                continue;
            }

            $connection = $pending->exchange;
            $httpVersion =
                self::httpVersionFromResponseState($responseState)
                    ?? self::httpVersionFromCurlInfo($completedHandle);
            $policyError = $this->createHttpVersionPolicyException(
                $pending,
                $httpVersion
            );
            if ($policyError !== null) {
                $this->failPendingRequest($pending, $policyError);

                continue;
            }

            $connection->recordHttpVersion($httpVersion);
            $connection->markUsed();
            $body = $responseState->body();

            if ($responseState->isStreaming()) {
                if (!$responseState->responseDelivered) {
                    $this->failPendingRequest(
                        $pending,
                        new NetworkException(
                            'Streamed HTTP response was not delivered.',
                            $pending->request
                        )
                    );

                    continue;
                }

                $cleanupError =
                    $this->finalizePendingRequest($pending);
                if ($cleanupError !== null) {
                    $responseState->failTrailers($cleanupError);
                    $body?->fail($cleanupError);

                    continue;
                }

                $responseState->completeTrailers();
                $body?->end();

                continue;
            }

            $contents = $responseState->buffersResponseBody()
                ? $responseState->bufferedBody()
                : \curl_multi_getcontent($completedHandle);
            $cleanupError = $this->finalizePendingRequest($pending);
            if ($cleanupError !== null) {
                $responseState->failTrailers($cleanupError);
                $pending->resumeThrowing($cleanupError);

                continue;
            }

            $responseState->completeTrailers();
            $pending->resumeWith($contents ?? '');
        }

        return $madeProgress;
    }

    /**
     * Deliver streamed responses outside cURL callbacks.
     */
    private function deliverStreamedResponses(): bool
    {
        if ($this->streamReady === []) {
            return false;
        }

        $madeProgress = false;
        $ready = $this->streamReady;
        $this->streamReady = [];

        foreach ($ready as $handleId => $_) {
            $pending = $this->pending[$handleId] ?? null;
            if ($pending === null
                || !$pending->responseState
                    ->isStreamedResponseReady()
            ) {
                continue;
            }

            $responseState = $pending->responseState;
            $error = $this
                ->createHttpVersionPolicyExceptionFromState(
                    $pending,
                    $responseState
                );

            if ($error === null) {
                try {
                    $response = $this
                        ->createStreamedResponse($responseState);
                } catch (\Throwable $cause) {
                    $error = $this->createTransportException(
                        'Invalid HTTP response.',
                        $pending->request,
                        $cause,
                        $pending->uploadState
                    );
                }
            }

            if ($error !== null) {
                $pending = $this->takePendingRequest($handleId);
                if ($pending !== null) {
                    $this->failPendingRequest($pending, $error);
                }
            } else {
                $responseState->responseDelivered = true;
                $pending->resumeWith($response);
            }

            $madeProgress = true;
        }

        return $madeProgress;
    }

    /**
     * Schedule an earlier timeout deadline when needed.
     *
     * @return void
     */
    private function scheduleTimeoutForPending(
        PendingRequest $pending
    ): void
    {
        $deadline = $this->timeoutDeadlineForPending($pending);
        if ($deadline === null
            || (
                $this->timeoutDeadline !== null
                && $this->timeoutDeadline <= $deadline
            )
        ) {
            return;
        }

        $this->armTimeoutTimer($deadline);
    }

    /**
     * Arm the shared request timeout timer.
     *
     * @return void
     */
    private function armTimeoutTimer(float $deadline): void
    {
        $this->cancelTimeoutTimer();
        $this->timeoutDeadline = $deadline;
        $this->timeoutTimerId = $this->eventLoop->schedule(
            \max(0.0, $deadline - \microtime(true)),
            function () use ($deadline): void {
                if ($this->timeoutDeadline !== $deadline) {
                    return;
                }

                $this->timeoutTimerId = null;
                $this->timeoutDeadline = null;
                $this->cancelPollTimer();

                if ($this->pending === []) {
                    return;
                }

                $this->polling = true;
                $madeProgress = false;

                try {
                    $madeProgress = $this->poll();
                    $this->updatePollInterval($madeProgress);

                    $now = \microtime(true);
                    $expired = [];

                    foreach ($this->pending
                        as $handleId => $pending
                    ) {
                        $options = $pending->options;
                        $responseState =
                            $pending->responseState;

                        if (!$responseState->paused
                            && $responseState->readTimedOut(
                                $options->readTimeout,
                                $now
                            )
                        ) {
                            $expired[] = $handleId;
                        }
                    }

                    foreach ($expired as $handleId) {
                        $pending = $this->takePendingRequest(
                            $handleId
                        );
                        if ($pending === null) {
                            continue;
                        }

                        $message = 'HTTP response read timed out after '
                            . "{$pending->options->readTimeout} "
                            . 'seconds of inactivity.';
                        $uploadBody =
                            $pending->uploadState?->body;
                        $this->failPendingRequest(
                            $pending,
                            new NetworkException(
                                $message,
                                request: $pending->request,
                                requestBodyStarted:
                                    $uploadBody?->started()
                                        ?? false,
                                requestBodyFullyConsumed:
                                    $uploadBody?->fullyConsumed()
                                        ?? false,
                                requestBodySeekable:
                                    $uploadBody?->isSeekable()
                            )
                        );
                    }

                    $nextDeadline = null;

                    foreach ($this->pending as $pending) {
                        $candidate =
                            $this->timeoutDeadlineForPending(
                                $pending
                            );
                        if ($candidate !== null
                            && (
                                $nextDeadline === null
                                || $candidate < $nextDeadline
                            )
                        ) {
                            $nextDeadline = $candidate;
                        }
                    }

                    if ($nextDeadline !== null) {
                        $this->armTimeoutTimer(
                            $nextDeadline
                        );
                    }
                } finally {
                    $this->polling = false;
                    $this->scheduleNextPoll($madeProgress);
                }
            }
        );
    }

    /**
     * Get the earliest timeout deadline for a request.
     */
    private function timeoutDeadlineForPending(
        PendingRequest $pending
    ): ?float
    {
        $responseState = $pending->responseState;

        return $responseState->paused
            ? null
            : $responseState->readTimeoutDeadline(
                $pending->options->readTimeout
            );
    }

    /**
     * Cancel the shared request timeout timer.
     *
     * @return void
     */
    private function cancelTimeoutTimer(): void
    {
        if ($this->timeoutTimerId !== null) {
            $this->eventLoop->cancel($this->timeoutTimerId);
        }

        $this->timeoutTimerId = null;
        $this->timeoutDeadline = null;
    }

    private function takePendingRequest(int $handleId): ?PendingRequest
    {
        $pending = $this->pending[$handleId] ?? null;
        if ($pending === null) {
            return null;
        }

        unset($this->pending[$handleId]);
        unset($this->progressScanMasks[$handleId]);
        unset($this->streamReady[$handleId]);

        if ($pending->cancellation !== null
            && $pending->cancellationSubscription !== null
        ) {
            $pending->cancellation->unsubscribe(
                $pending->cancellationSubscription
            );
            $pending->cancellationSubscription = null;
        }

        if ($this->pending === []) {
            $this->cancelPollTimer();
            $this->cancelTimeoutTimer();
        }

        return $pending;
    }

    private function failPendingRequest(
        PendingRequest $pending,
        \Throwable $error
    ): void
    {
        $pending->exchange->markUnhealthy();
        $responseState = $pending->responseState;
        $responseDelivered = $responseState->responseDelivered;
        $responseBody = $responseState->body();
        $responseState->failTrailers($error);
        $this->finalizePendingRequest($pending);

        if ($responseDelivered) {
            $responseBody?->fail($error);

            return;
        }

        $pending->resumeThrowing($error);
    }

    /**
     * Clean up setup state and release an exchange.
     */
    private function cleanupExchange(
        CurlConnection $exchange,
        RequestInterface $request,
        ?UploadState $uploadState
    ): ?HttpException
    {
        $cleanupError = null;

        try {
            $this->cleanupUploadState($uploadState);
        } catch (\Throwable $error) {
            $cleanupError = $this->createTransportException(
                'HTTP transfer cleanup failed.',
                $request,
                $error,
                $uploadState
            );
            $exchange->markUnhealthy();
        }

        try {
            $this->connectionManager->release($exchange);
        } catch (\Throwable $error) {
            $cleanupError ??= $this->createTransportException(
                'HTTP connection release failed.',
                $request,
                $error,
                $uploadState
            );
            $exchange->markUnhealthy();
        }

        return $cleanupError;
    }

    /**
     * Release all transport resources held by a pending request.
     *
     * @return HttpException|null A normalized cleanup failure.
     */
    private function finalizePendingRequest(
        PendingRequest $pending
    ): ?HttpException
    {
        $responseState = $pending->responseState;
        $responseState->paused = false;
        $responseState->resumeScheduled = false;
        $responseState->pausedChunkLength = 0;
        $cleanupError = null;

        try {
            $this->cleanupUploadState($pending->uploadState);
        } catch (\Throwable $error) {
            $cleanupError = $this->createTransportException(
                'HTTP transfer cleanup failed.',
                $pending->request,
                $error,
                $pending->uploadState
            );
            $pending->exchange->markUnhealthy();
        }

        try {
            $result = \curl_multi_remove_handle(
                $this->multiHandle,
                $pending->getHandle()
            );
            if ($result !== CURLM_OK) {
                throw new \RuntimeException(
                    'Unable to remove cURL handle: '
                        . \curl_multi_strerror($result)
                );
            }
        } catch (\Throwable $error) {
            $cleanupError ??= $this->createTransportException(
                'HTTP transfer cleanup failed.',
                $pending->request,
                $error,
                $pending->uploadState
            );
            $pending->exchange->markUnhealthy();
        }

        try {
            $this->connectionManager->release(
                $pending->exchange
            );
        } catch (\Throwable $error) {
            $cleanupError ??= $this->createTransportException(
                'HTTP connection release failed.',
                $pending->request,
                $error,
                $pending->uploadState
            );
            $pending->exchange->markUnhealthy();
        }

        return $cleanupError;
    }

    /**
     * Propagate a request body read failure.
     *
     * @return void
     */
    private function failUploadRead(
        PendingRequest $pending,
        UploadState $uploadState,
        \Throwable $readError
    ): void
    {
        $this->failPendingRequest(
            $pending,
            new HttpException(
                'Request body stream read failed: '
                    . $readError->getMessage(),
                request: $pending->request,
                previous: $readError,
                requestBodyStarted: $uploadState->body->started(),
                requestBodyFullyConsumed:
                    $uploadState->body->fullyConsumed(),
                requestBodySeekable:
                    $uploadState->body->isSeekable()
            )
        );
    }

    /**
     * Create an exception for response body callback failures.
     */
    private function createBodyWriteException(
        PendingRequest $pending,
        \Throwable $bodyWriteError
    ): HttpException
    {
        $uploadBody = $pending->uploadState?->body;

        return new HttpException(
            $bodyWriteError->getMessage(),
            request: $pending->request,
            previous: $bodyWriteError,
            requestBodyStarted: $uploadBody?->started() ?? false,
            requestBodyFullyConsumed:
                $uploadBody?->fullyConsumed() ?? false,
            requestBodySeekable: $uploadBody?->isSeekable()
        );
    }

    /**
     * Create an exception when parsed headers violate the version policy.
     */
    private function createHttpVersionPolicyExceptionFromState(
        PendingRequest $pending,
        CurlResponseState $responseState
    ): ?NetworkException
    {
        $httpVersion = self::httpVersionFromResponseState($responseState);
        if ($httpVersion === null) {
            return null;
        }

        return $this->createHttpVersionPolicyException(
            $pending,
            $httpVersion
        );
    }

    /**
     * Create an exception when the negotiated version violates policy.
     */
    private function createHttpVersionPolicyException(
        PendingRequest $pending,
        HttpVersion $httpVersion
    ): ?NetworkException
    {
        if ($this->connectConfig->versionPolicy
                !== HTTPVersionPolicy::EnforceHTTP_2
            || $httpVersion === HttpVersion::HTTP_2
        ) {
            return null;
        }

        $uploadBody = $pending->uploadState?->body;

        return new NetworkException(
            'HTTP/2 was required, but the transfer negotiated '
                . "HTTP/{$httpVersion->value}.",
            request: $pending->request,
            requestBodyStarted: $uploadBody?->started() ?? false,
            requestBodyFullyConsumed:
                $uploadBody?->fullyConsumed() ?? false,
            requestBodySeekable: $uploadBody?->isSeekable()
        );
    }

    /**
     * Reject a request when its cancellation signal is set.
     *
     * @return void
     */
    private function throwIfCancelled(
        RequestInterface $request,
        ?CancellationInterface $cancellation
    ): void
    {
        $reason = $cancellation?->reason();
        if ($reason === null) {
            return;
        }

        throw $this->createCancellationException(
            $request,
            null,
            $reason
        );
    }

    /**
     * Create a request-aware cancellation exception.
     */
    private function createCancellationException(
        RequestInterface $request,
        ?UploadState $uploadState,
        CancelledException $reason
    ): RequestCancelledException
    {
        $body = $uploadState?->body;
        if ($body === null
            && $request->getBody() instanceof RequestStreamInterface
        ) {
            $body = $request->getBody();
        }

        return new RequestCancelledException(
            'HTTP request was cancelled.',
            request: $request,
            previous: $reason,
            requestBodyStarted: $body?->started() ?? false,
            requestBodyFullyConsumed:
                $body?->fullyConsumed() ?? false,
            requestBodySeekable: $body?->isSeekable()
        );
    }

    /**
     * Normalize an extension or cleanup failure at the transport boundary.
     */
    private function createTransportException(
        string $message,
        RequestInterface $request,
        \Throwable $error,
        ?UploadState $uploadState = null
    ): HttpException
    {
        if ($error instanceof HttpException) {
            return $error;
        }

        $body = $uploadState?->body;
        if ($body === null
            && $request->getBody() instanceof RequestStreamInterface
        ) {
            $body = $request->getBody();
        }

        return new NetworkException(
            "{$message} {$error->getMessage()}",
            request: $request,
            previous: $error,
            requestBodyStarted: $body?->started() ?? false,
            requestBodyFullyConsumed:
                $body?->fullyConsumed() ?? false,
            requestBodySeekable: $body?->isSeekable()
        );
    }

    /**
     * Reject options unsupported by PHP's cURL extension.
     *
     * @return void
     * @throws \InvalidArgumentException If request trailers are configured.
     */
    private function validateRequestOptions(
        RequestInterface $request,
        HttpRequestOptions $options
    ): void
    {
        if ($options->trailers !== null) {
            throw new RequestException(
                'CurlClient does not support request trailers because the '
                    . 'PHP cURL extension does not expose libcurl request '
                    . 'trailer callbacks.',
                $request
            );
        }
    }

    /**
     * Convert timeout seconds to cURL milliseconds.
     */
    private static function timeoutToCurlMilliseconds(float $seconds): int
    {
        return $seconds === 0.0
            ? 0
            : \max(1, (int) \ceil($seconds * 1000));
    }

    /**
     * Get a stable identity for a cURL easy handle object.
     */
    private static function handleId(\CurlHandle $handle): int
    {
        return \spl_object_id($handle);
    }

    /**
     * Determine whether cURL can read and rewind the body natively.
     *
     * @phpstan-assert-if-true ResourceRequestStream $body
     */
    private static function canUseNativeReadData(
        RequestStreamInterface $body
    ): bool
    {
        return $body instanceof ResourceRequestStream
            && $body->isReadable()
            && $body->getMetadata('wrapper_type') !== 'user-space';
    }

    /**
     * Get downloaded byte count from cURL transfer info.
     */
    private static function downloadedBytes(\CurlHandle $handle): int
    {
        $info = \defined('CURLINFO_SIZE_DOWNLOAD_T')
            ? \constant('CURLINFO_SIZE_DOWNLOAD_T')
            : CURLINFO_SIZE_DOWNLOAD;

        return (int) \curl_getinfo($handle, $info);
    }

    /**
     * Get uploaded byte count from cURL transfer info.
     */
    private static function uploadedBytes(\CurlHandle $handle): int
    {
        $info = \defined('CURLINFO_SIZE_UPLOAD_T')
            ? \constant('CURLINFO_SIZE_UPLOAD_T')
            : CURLINFO_SIZE_UPLOAD;

        return (int) \curl_getinfo($handle, $info);
    }

    /**
     * Determine whether cURL reported no connect phase duration.
     */
    private static function connectTimeIsZero(\CurlHandle $handle): bool
    {
        if (\defined('CURLINFO_CONNECT_TIME_T')) {
            return \curl_getinfo(
                $handle,
                \constant('CURLINFO_CONNECT_TIME_T')
            ) === 0;
        }

        return \curl_getinfo($handle, CURLINFO_CONNECT_TIME) === 0.0;
    }

    /**
     * Map parsed response protocol to a connection HTTP version.
     */
    private static function httpVersionFromResponseState(
        CurlResponseState $responseState
    ): ?HttpVersion
    {
        return match ($responseState->protocolVersion) {
            '2', '2.0' => HttpVersion::HTTP_2,
            '1.1' => HttpVersion::HTTP_1_1,
            default => null
        };
    }

    /**
     * Map cURL transfer info to a connection HTTP version.
     */
    private static function httpVersionFromCurlInfo(
        \CurlHandle $handle
    ): HttpVersion
    {
        if (!\defined('CURLINFO_HTTP_VERSION')) {
            return HttpVersion::HTTP_1_1;
        }

        $version = \curl_getinfo(
            $handle,
            \constant('CURLINFO_HTTP_VERSION')
        );

        foreach ([
            'CURL_HTTP_VERSION_2_0',
            'CURL_HTTP_VERSION_2',
            'CURL_HTTP_VERSION_2TLS',
            'CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE'
        ] as $http2Version) {
            if (\defined($http2Version)
                && $version === \constant($http2Version)
            ) {
                return HttpVersion::HTTP_2;
            }
        }

        return HttpVersion::HTTP_1_1;
    }

    /**
     * Release request upload state.
     *
     * @return void
     */
    private function cleanupUploadState(
        ?UploadState $uploadState
    ): void
    {
        if ($uploadState === null) {
            return;
        }

        $uploadState->retainedFinalChunk = null;

        if (!$uploadState->body
                instanceof AsyncRequestStreamInterface
        ) {
            return;
        }

        if ($uploadState->readinessSubscription !== null) {
            $uploadState->body->unsubscribe(
                $uploadState->readinessSubscription
            );
            $uploadState->readinessSubscription = null;
        }
        $uploadState->body->detachConsumer();

        $uploadState->paused = false;
        $uploadState->resumeScheduled = false;
    }

    private static function verifyUploadBodyExhausted(
        UploadState $uploadState
    ): void
    {
        if ($uploadState->body->fullyConsumed()
            || $uploadState->body->eof()
        ) {
            return;
        }

        $extra = $uploadState->body->read(1);
        if ($extra !== '') {
            throw new \UnexpectedValueException(
                'Request body contains more bytes than the declared '
                    . "length of {$uploadState->expectedLength}."
            );
        }

        if (!$uploadState->body->eof()) {
            throw new \RuntimeException(
                'Request body returned no data before reaching EOF.'
            );
        }
    }

    /**
     * Create a bounded response body attached to one cURL transfer.
     */
    private function createStreamingResponseStream(
        int $handleId,
        HttpRequestOptions $options
    ): StreamingResponseStream
    {
        return new StreamingResponseStream(
            $this->eventLoop,
            $options->responseBufferSize,
            function () use ($handleId): void {
                $pending =
                    $this->pending[$handleId] ?? null;
                if ($pending === null) {
                    return;
                }

                $responseState = $pending->responseState;
                if (!$responseState->paused
                    || $responseState->resumeScheduled
                    || !$responseState->canResume()
                ) {
                    return;
                }

                $responseState->resumeScheduled = true;
                $this->eventLoop->defer(
                    function () use ($handleId): void {
                        $pending =
                            $this->pending[$handleId]
                                ?? null;
                        if ($pending === null) {
                            return;
                        }

                        $responseState =
                            $pending->responseState;
                        $responseState->resumeScheduled =
                            false;
                        if (!$responseState->paused
                            || !$responseState->canResume()
                        ) {
                            return;
                        }

                        $responseState->paused = false;
                        $responseState->pausedChunkLength = 0;
                        $responseState->recordReadActivity();
                        $this->scheduleTimeoutForPending(
                            $pending
                        );
                        $pause = 0;
                        if ($pending->uploadState?->paused) {
                            $pause |= CURLPAUSE_SEND;
                        }

                        if ($pending->responseState->paused) {
                            $pause |= CURLPAUSE_RECV;
                        }
                        $result = \curl_pause(
                            $pending->getHandle(),
                            $pause
                        );
                        if ($result === CURLE_OK) {
                            return;
                        }

                        $taken =
                            $this->takePendingRequest(
                                $handleId
                            );
                        if ($taken !== null) {
                            $this->failPendingRequest(
                                $taken,
                                new NetworkException(
                                    'Unable to resume response body: '
                                        . \curl_strerror(
                                            $result
                                        ),
                                    $taken->request
                                )
                            );
                        }
                    }
                );
            },
            function () use ($handleId): void {
                $pending =
                    $this->takePendingRequest($handleId);
                if ($pending === null) {
                    return;
                }

                $this->failPendingRequest(
                    $pending,
                    new NetworkException(
                        'Streamed response body closed before '
                            . 'transfer completed.',
                        $pending->request
                    )
                );
            }
        );
    }

    private function createStreamedResponse(
        CurlResponseState $responseState
    ): ResponseInterface
    {
        $body = $responseState->body();
        if ($body === null) {
            throw new \LogicException(
                'Streamed response body is unavailable.'
            );
        }

        if ($responseState->headerError !== null) {
            throw $responseState->headerError;
        }

        $response = Response::fromNormalized(
            $responseState->status,
            $responseState->headers,
            $body,
            $responseState->protocolVersion,
            $responseState->reason,
            $responseState->trailers()
        );
        $responseState->releaseBodyOwnership();

        return $response;
    }

}
