<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Smithy\Http\Client\Connection\ConnectionHealthStatus;
use Smithy\Http\Client\Connection\ConnectionState;
use Smithy\Http\Client\Connection\HttpConnectionInterface;
use Smithy\Http\Client\Connection\HttpVersion;
use Smithy\Http\Client\Connection\RequestContext;

/**
 * Track a cURL easy handle used for HTTP transfers.
 *
 * The cURL multi handle owns libcurl's reusable socket cache. Closing this
 * wrapper closes the easy handle and prevents wrapper reuse.
 *
 * @internal
 */
final class CurlConnection implements HttpConnectionInterface
{
    private ConnectionState $state;
    private ConnectionHealthStatus $health = ConnectionHealthStatus::UNKNOWN;
    private readonly float $createdAt;
    private float $lastUsedAt;
    private int $requestCount = 0;

    public function __construct(
        private \CurlHandle $handle,
        private readonly RequestContext $requestContext,
        private HttpVersion $httpVersion = HttpVersion::HTTP_1_1,
        float $createdAt = 0.0
    )
    {
        $now = \microtime(true);
        $this->createdAt = $createdAt > 0.0 ? $createdAt : $now;
        $this->lastUsedAt = $now;
        $this->state = ConnectionState::CONNECTED;
    }

    /**
     * Access the raw cURL handle for transport operations.
     *
     * Package-internal: used by CurlExchange and CurlClient
     * for curl_exec / curl_multi operations.
     */
    public function getHandle(): \CurlHandle
    {
        return $this->handle;
    }

    /**
     * Reset cURL options while preserving reusable handle state.
     *
     * Called when returning a connection to the pool. \curl_reset()
     * clears all options while libcurl's multi handle remains responsible
     * for any underlying socket reuse.
     *
     * @return void
     */
    public function resetForReuse(): void
    {
        \curl_reset($this->handle);
    }

    /**
     * Record that a request was successfully completed.
     *
     * @return void
     */
    public function markUsed(): void
    {
        $this->lastUsedAt = \microtime(true);
        $this->requestCount++;
        $this->health = ConnectionHealthStatus::HEALTHY;
    }

    /**
     * Record the negotiated HTTP protocol version.
     *
     * @return void
     */
    public function recordHttpVersion(HttpVersion $httpVersion): void
    {
        $this->httpVersion = $httpVersion;
    }

    /** @inheritDoc */
    public function markUnhealthy(): void
    {
        $this->health = ConnectionHealthStatus::UNHEALTHY;
    }

    /**
     * Close the easy handle and prevent this wrapper from being reused.
     *
     * The cURL multi handle owns reusable socket-cache eviction.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $this->state = ConnectionState::CLOSED;
        \curl_close($this->handle);
    }

    /** @inheritDoc */
    public function state(): ConnectionState
    {
        return $this->state;
    }

    /** @inheritDoc */
    public function healthStatus(): ConnectionHealthStatus
    {
        return $this->health;
    }

    /** @inheritDoc */
    public function httpVersion(): HttpVersion
    {
        return $this->httpVersion;
    }

    /** @inheritDoc */
    public function requestContext(): RequestContext
    {
        return $this->requestContext;
    }

    /** @inheritDoc */
    public function createdAt(): float
    {
        return $this->createdAt;
    }

    /** @inheritDoc */
    public function lastUsedAt(): float
    {
        return $this->lastUsedAt;
    }

    /** @inheritDoc */
    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * Whether the cURL handle had an error on its last operation.
     */
    public function hasError(): bool
    {
        return \curl_errno($this->handle) !== CURLE_OK;
    }
}
