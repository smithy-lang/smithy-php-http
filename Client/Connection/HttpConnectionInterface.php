<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

/**
 * A single HTTP connection with state and health tracking.
 *
 * Represents a stateful object wrapping a TCP/TLS socket with a lifecycle
 * state machine and health monitoring. The connection knows its protocol
 * version and target.
 *
 * Implementations wrap transport-specific handles (e.g. \CurlHandle)
 * and expose them only to their corresponding exchange and factory
 * implementations.
 */
interface HttpConnectionInterface
{
    /**
     * Close the connection and release underlying resources.
     *
     * After close(), state() must return CLOSED and reuse is invalid.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Current lifecycle state.
     */
    public function state(): ConnectionState;

    /**
     * Current health status.
     *
     * HEALTHY: connection completed its last request successfully.
     * UNHEALTHY: connection experienced errors or throughput degradation.
     * UNKNOWN: health is pending.
     */
    public function healthStatus(): ConnectionHealthStatus;

    /**
     * Mark the connection as unhealthy.
     *
     * Called by the client or connection manager when a transport
     * error occurs on this connection.
     *
     * @return void
     */
    public function markUnhealthy(): void;

    /**
     * The request context (host, port, scheme, proxy) for this connection.
     */
    public function requestContext(): RequestContext;

    /**
     * Timestamp (microtime) when this connection was last used.
     */
    public function lastUsedAt(): float;
}
