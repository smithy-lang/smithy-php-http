<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Smithy\Async\EventLoopInterface;
use Smithy\Async\RevoltEventLoop;
use Smithy\Http\Client\Cancellation\CancellationInterface;
use Smithy\Http\Client\Cancellation\CancelledException;
use Smithy\Http\Client\Configuration\ConnectConfiguration;
use Smithy\Http\Client\Configuration\ConnectionManagerConfiguration;
use Smithy\Http\Client\Connection\ConnectionFactoryInterface;
use Smithy\Http\Client\Connection\ConnectionHealthStatus;
use Smithy\Http\Client\Connection\ConnectionManagerInterface;
use Smithy\Http\Client\Connection\ConnectionPoolInterface;
use Smithy\Http\Client\Connection\ConnectionState;
use Smithy\Http\Client\Connection\ExchangeInterface;
use Smithy\Http\Client\Connection\FifoConnectionPool;
use Smithy\Http\Client\Connection\HttpConnectionInterface;
use Smithy\Http\Client\Connection\RequestContext;
use Smithy\Http\Client\Exception\ConnectionAcquireTimeoutException;

/**
 * Manage cURL easy-handle wrappers.
 *
 * Leases exchanges from FIFO pools and enforces per-host concurrency.
 * This manager owns idle easy handles. libcurl's multi handle owns the
 * underlying reusable socket cache.
 *
 * @internal
 */
final class CurlConnectionManager implements ConnectionManagerInterface
{
    private readonly ConnectionFactoryInterface $connectionFactory;
    private readonly ConnectionManagerConfiguration $configuration;
    private readonly EventLoopInterface $eventLoop;
    /**
     * FIFO pools of idle easy-handle wrappers, keyed by CacheKey string.
     *
     * @var array<string, ConnectionPoolInterface>
     */
    private array $pools = [];
    /**
     * Active (checked-out) connection count per cache key.
     *
     * @var array<string, int>
     */
    private array $activeCount = [];
    /** Total idle easy-handle wrappers across all pools. */
    private int $totalIdle = 0;
    /** Current pool generation. */
    private int $generation = 0;
    /** @var array<int, int> Connection object ID to pool generation. */
    private array $connectionGenerations = [];
    /** @var array<int, true> Checked-out connection object IDs. */
    private array $leasedConnectionIds = [];
    /**
     * Fibers waiting for a transfer permit, keyed by cache key.
     *
     * @var array<string, array<int, array{
     *     resume: callable(mixed=): void,
     *     throw: callable(\Throwable): void,
     *     timer: string|null,
     *     cancellation: CancellationInterface|null,
     *     subscription: int|null
     * }>>
     */
    private array $waiters = [];
    private int $nextWaiterId = 0;

    public function __construct(
        ConnectionManagerConfiguration $configuration =
            new ConnectionManagerConfiguration(),
        ?ConnectionFactoryInterface $connectionFactory = null,
        ?EventLoopInterface $eventLoop = null
    )
    {
        $this->configuration = $configuration;
        $this->connectionFactory = $connectionFactory
            ?? new CurlConnectionFactory();
        $this->eventLoop = $eventLoop ?? new RevoltEventLoop();
    }

    /**
     * Lease an exchange for a request context.
     *
     * @throws CancelledException If cancellation is requested.
     * @throws ConnectionAcquireTimeoutException On acquisition timeout.
     */
    public function lease(
        RequestContext $requestContext,
        ConnectConfiguration $connectConfig = new ConnectConfiguration(),
        ?CancellationInterface $cancellation = null
    ): ExchangeInterface
    {
        $key = (string) $requestContext->cacheKey();
        $this->acquirePermit(
            $key,
            $requestContext->host,
            $cancellation
        );

        try {
            $connection = $this->acquireFromPool($key);

            if ($connection === null) {
                $connection = $this->validateConnection(
                    $this->connectionFactory->create(
                        $requestContext,
                        $connectConfig
                    )
                );
            } else {
                $this->reapplyConnectConfig(
                    $connection,
                    $connectConfig
                );
            }

            $connectionId = \spl_object_id($connection);
            $this->connectionGenerations[$connectionId] = $this->generation;
            $this->leasedConnectionIds[$connectionId] = true;

            return new CurlExchange($connection);
        } catch (\Throwable $error) {
            $this->releasePermit($key);

            throw $error;
        }
    }

    /**
     * Release an exchange back to the easy-handle pool.
     *
     * @return void
     */
    public function release(ExchangeInterface $exchange): void
    {
        $connection = $exchange->getConnection();
        $key = (string) $connection->requestContext()->cacheKey();
        $connectionId = \spl_object_id($connection);
        if (!isset($this->leasedConnectionIds[$connectionId])) {
            throw new \LogicException(
                'Cannot release a connection that is not currently leased.'
            );
        }

        $isStale = ($this->connectionGenerations[$connectionId] ?? null)
            !== $this->generation;

        try {
            if ($isStale
                || $connection->healthStatus()
                    === ConnectionHealthStatus::UNHEALTHY
                || $connection->state() === ConnectionState::CLOSED
            ) {
                $connection->close();
                unset($this->connectionGenerations[$connectionId]);

                return;
            }

            if ($connection instanceof CurlConnection
                && $connection->hasError()
            ) {
                $connection->close();
                unset($this->connectionGenerations[$connectionId]);

                return;
            }

            $pool = $this->pools[$key] ?? null;
            if (($pool?->count() ?? 0)
                    >= $this->configuration->maxIdlePerHost
                || $this->totalIdle
                    >= $this->configuration->maxIdleTotal
            ) {
                $connection->close();
                unset($this->connectionGenerations[$connectionId]);

                return;
            }

            if ($connection instanceof CurlConnection) {
                $connection->resetForReuse();
            }

            ($pool ??= $this->getPool($key))->offerLast($connection);
            $this->totalIdle++;
        } finally {
            unset($this->leasedConnectionIds[$connectionId]);
            $this->releasePermit($key);
        }
    }

    /**
     * Close idle easy-handle wrappers that exceeded the idle timeout.
     *
     * Actual reusable socket caching is owned by libcurl's multi handle.
     *
     * @return void
     */
    public function prune(): void
    {
        $idleTimeout = $this->configuration->idleTimeout;

        foreach ($this->pools as $key => $pool) {
            $pruned = $pool->prune($idleTimeout);
            $this->totalIdle -= \count($pruned);
            foreach ($pruned as $connection) {
                unset(
                    $this->connectionGenerations[
                        \spl_object_id($connection)
                    ]
                );
            }

            if ($pool->isEmpty()) {
                unset($this->pools[$key]);
            }
        }
    }

    /**
     * Close idle easy handles, expire active wrappers, and fail waiters.
     *
     * Actual reusable socket caching is owned by libcurl's multi handle.
     *
     * @return void
     */
    public function clear(): void
    {
        foreach ($this->pools as $pool) {
            $pool->clear();
        }

        $this->pools = [];
        $this->totalIdle = 0;
        $this->generation++;
        foreach (\array_keys($this->connectionGenerations) as $connectionId) {
            if (!isset($this->leasedConnectionIds[$connectionId])) {
                unset($this->connectionGenerations[$connectionId]);
            }
        }
        $this->failWaiters();
    }

    /**
     * Get the total number of idle easy-handle wrappers.
     */
    public function idleCount(): int
    {
        return $this->totalIdle;
    }

    /**
     * Get the connection manager configuration.
     */
    public function getConfiguration(): ConnectionManagerConfiguration
    {
        return $this->configuration;
    }

    /**
     * Get the active connection count for a cache key.
     */
    public function activeCount(string $key): int
    {
        return $this->activeCount[$key] ?? 0;
    }

    /**
     * Close managed easy handles.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->clear();
    }

    /**
     * Get or create the FIFO pool for a cache key.
     */
    private function getPool(string $key): ConnectionPoolInterface
    {
        return $this->pools[$key] ??= new FifoConnectionPool();
    }

    /**
     * Try to acquire a healthy connection from the FIFO pool.
     *
     * Skips connections that are idle-timed-out, unhealthy, or closed.
     * Returns null if no usable connection is available.
     */
    private function acquireFromPool(string $key): ?CurlConnection
    {
        $pool = $this->pools[$key] ?? null;

        if ($pool === null || $pool->isEmpty()) {
            return null;
        }

        $idleTimeout = $this->configuration->idleTimeout;
        $now = \microtime(true);

        // Pool order preserves FIFO lease behavior.
        while (!$pool->isEmpty()) {
            $connection = $pool->getFirst();
            $this->totalIdle--;

            if ($connection === null) {
                break;
            }

            if ($connection->state() === ConnectionState::CLOSED) {
                $connectionId = \spl_object_id($connection);
                unset($this->connectionGenerations[$connectionId]);

                continue;
            }

            if (($now - $connection->lastUsedAt()) > $idleTimeout) {
                $connection->close();
                $connectionId = \spl_object_id($connection);
                unset($this->connectionGenerations[$connectionId]);

                continue;
            }

            if ($connection->healthStatus()
                    === ConnectionHealthStatus::UNHEALTHY
            ) {
                $connection->close();
                $connectionId = \spl_object_id($connection);
                unset($this->connectionGenerations[$connectionId]);

                continue;
            }

            if ($connection instanceof CurlConnection) {
                return $connection;
            }

            $connection->close();
            unset($this->connectionGenerations[\spl_object_id($connection)]);
        }

        if ($pool->isEmpty()) {
            unset($this->pools[$key]);
        }

        return null;
    }

    /**
     * Re-apply connection configuration to a pooled connection.
     *
     * curl_reset() clears connection options from pooled handles.
     *
     * @return void
     */
    private function reapplyConnectConfig(
        CurlConnection $connection,
        ConnectConfiguration $config
    ): void
    {
        $handle = $connection->getHandle();
        CurlConnectionFactory::applyConnectConfiguration(
            $handle,
            $config,
            $connection->requestContext()->proxy
        );
    }

    /**
     * Validate that a configured factory produced a cURL connection.
     */
    private function validateConnection(
        HttpConnectionInterface $connection
    ): CurlConnection
    {
        if ($connection instanceof CurlConnection) {
            return $connection;
        }

        $connection->close();

        throw new \LogicException(
            'CurlConnectionManager requires factories to create '
                . 'CurlConnection instances.'
        );
    }

    /**
     * Acquire capacity for a destination.
     *
     * @return void
     * @throws ConnectionAcquireTimeoutException If capacity remains full.
     */
    private function acquirePermit(
        string $key,
        string $host,
        ?CancellationInterface $cancellation
    ): void
    {
        $cancellation?->throwIfCancelled();

        if ($this->tryAcquirePermit($key)) {
            return;
        }

        $this->eventLoop->suspend(
            function (
                callable $resume,
                callable $throw
            ) use (
                $key,
                $host,
                $cancellation
            ): void
            {
                $cancellation?->throwIfCancelled();
                if ($this->tryAcquirePermit($key)) {
                    $resume();

                    return;
                }

                $timeout = $this->configuration
                    ->connectionAcquireTimeout;
                $waiterId = ++$this->nextWaiterId;
                $this->waiters[$key][$waiterId] = [
                    'resume' => $resume,
                    'throw' => $throw,
                    'timer' => null,
                    'cancellation' => $cancellation,
                    'subscription' => null
                ];

                if ($timeout > 0.0) {
                    $timer = $this->eventLoop->schedule(
                        $timeout,
                        function () use (
                            $key,
                            $host,
                            $waiterId
                        ): void
                        {
                            $waiter = $this->removeWaiter(
                                $key,
                                $waiterId
                            );
                            if ($waiter === null) {
                                return;
                            }

                            $this->cleanupWaiter($waiter);
                            $waiter['throw'](
                                $this->createAcquireTimeoutException(
                                    $host
                                )
                            );
                        }
                    );
                    $this->waiters[$key][$waiterId]['timer'] = $timer;
                }

                if ($cancellation === null) {
                    return;
                }

                $subscription = $cancellation->subscribe(
                    function (CancelledException $error) use (
                        $key,
                        $waiterId
                    ): void
                    {
                        $waiter = $this->removeWaiter(
                            $key,
                            $waiterId
                        );
                        if ($waiter === null) {
                            return;
                        }

                        $this->cleanupWaiter($waiter);
                        $waiter['throw']($error);
                    }
                );
                if (isset($this->waiters[$key][$waiterId])) {
                    $this->waiters[$key][$waiterId]['subscription']
                        = $subscription;
                } else {
                    $cancellation->unsubscribe($subscription);
                }
            }
        );
    }

    /**
     * Try to claim one active connection permit.
     */
    private function tryAcquirePermit(string $key): bool
    {
        if (($this->activeCount[$key] ?? 0)
                >= $this->configuration->maxConcurrency
        ) {
            return false;
        }

        $this->activeCount[$key]
            = ($this->activeCount[$key] ?? 0) + 1;

        return true;
    }

    /**
     * Release capacity and resume the oldest waiter.
     *
     * @return void
     */
    private function releasePermit(string $key): void
    {
        if (($this->activeCount[$key] ?? 0) > 0) {
            $this->activeCount[$key]--;
            if ($this->activeCount[$key] === 0) {
                unset($this->activeCount[$key]);
            }
        }

        while (($this->waiters[$key] ?? []) !== []) {
            $waiterId = \array_key_first($this->waiters[$key]);
            $waiter = $this->removeWaiter($key, $waiterId);
            if ($waiter === null) {
                continue;
            }

            $this->cleanupWaiter($waiter);
            $this->activeCount[$key]
                = ($this->activeCount[$key] ?? 0) + 1;
            $waiter['resume']();

            return;
        }
    }

    /**
     * Remove a queued waiter.
     *
     * @return array{
     *     resume: callable(mixed=): void,
     *     throw: callable(\Throwable): void,
     *     timer: string|null,
     *     cancellation: CancellationInterface|null,
     *     subscription: int|null
     * }|null
     */
    private function removeWaiter(
        string $key,
        int $waiterId
    ): ?array
    {
        $waiter = $this->waiters[$key][$waiterId] ?? null;
        unset($this->waiters[$key][$waiterId]);

        if (($this->waiters[$key] ?? []) === []) {
            unset($this->waiters[$key]);
        }

        return $waiter;
    }

    /**
     * Cancel a waiter's timer and cancellation subscription.
     *
     * @param array{
     *     resume: callable(mixed=): void,
     *     throw: callable(\Throwable): void,
     *     timer: string|null,
     *     cancellation: CancellationInterface|null,
     *     subscription: int|null
     * } $waiter
     *
     * @return void
     */
    private function cleanupWaiter(array $waiter): void
    {
        if ($waiter['timer'] !== null) {
            $this->eventLoop->cancel($waiter['timer']);
        }

        if ($waiter['cancellation'] !== null
            && $waiter['subscription'] !== null
        ) {
            $waiter['cancellation']->unsubscribe(
                $waiter['subscription']
            );
        }
    }

    private function createAcquireTimeoutException(
        string $host
    ): ConnectionAcquireTimeoutException
    {
        $timeout = $this->configuration->connectionAcquireTimeout;

        return new ConnectionAcquireTimeoutException(
            "Timed out after {$timeout}s waiting for a connection "
                . "to '{$host}'. All "
                . "{$this->configuration->maxConcurrency} "
                . 'connections are in use.'
        );
    }

    /**
     * Fail all fibers waiting for capacity.
     *
     * @return void
     */
    private function failWaiters(): void
    {
        foreach ($this->waiters as $key => $waiters) {
            foreach ($waiters as $waiterId => $waiter) {
                $waiter = $this->removeWaiter($key, $waiterId);
                if ($waiter === null) {
                    continue;
                }

                $this->cleanupWaiter($waiter);
                $waiter['throw'](
                    new \RuntimeException(
                        'Connection manager cleared while waiting '
                            . 'for capacity.'
                    )
                );
            }
        }
    }
}
