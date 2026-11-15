<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

use Smithy\Http\Client\Cancellation\CancellationInterface;
use Smithy\Http\Client\Cancellation\CancelledException;
use Smithy\Http\Client\Configuration\ConnectionManagerConfiguration;
use Smithy\Http\Client\Configuration\ConnectConfiguration;
use Smithy\Http\Client\Exception\ConnectionAcquireTimeoutException;

/**
 * Manage the lifecycle of HTTP connections.
 *
 * Leases protocol-agnostic exchanges and enforces connection concurrency.
 * Idle connections are pooled in FIFO order by RequestContext key.
 */
interface ConnectionManagerInterface
{
    /**
     * Lease an Exchange for sending a request.
     *
     * Reuses an idle connection or creates one when capacity is available.
     * If the concurrency limit is reached, waits for capacity until the
     * acquisition timeout expires.
     *
     * @param RequestContext $requestContext Target host, port, scheme, proxy.
     * @param ConnectConfiguration $connectConfig Connection settings.
     * @param CancellationInterface|null $cancellation Cancellation signal.
     *
     * @return ExchangeInterface A ready-to-use exchange.
     *
     * @throws CancelledException If cancellation is requested.
     * @throws ConnectionAcquireTimeoutException On acquisition timeout.
     */
    public function lease(
        RequestContext $requestContext,
        ConnectConfiguration $connectConfig = new ConnectConfiguration(),
        ?CancellationInterface $cancellation = null
    ): ExchangeInterface;

    /**
     * Release an Exchange back to the pool.
     *
     * The exchange returns to the FIFO pool when healthy and capacity
     * exists. Unhealthy exchanges and exchanges over pool capacity are
     * closed.
     *
     * @return void
     */
    public function release(ExchangeInterface $exchange): void;

    /**
     * Remove and close pooled exchanges that have been idle longer than
     * the idle timeout.
     *
     * @return void
     */
    public function prune(): void;

    /**
     * Close pooled exchanges and reset the pools.
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Get the total number of idle connections.
     */
    public function idleCount(): int;

    /**
     * Get the connection manager configuration.
     */
    public function getConfiguration(): ConnectionManagerConfiguration;
}
