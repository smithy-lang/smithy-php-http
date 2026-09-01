<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

/**
 * FIFO pool of idle HTTP connections.
 *
 * FIFO reuse rotates through pooled connections, keeping them active and
 * distributing load evenly.
 */
interface ConnectionPoolInterface
{
    /**
     * Retrieve and remove the first (oldest) connection.
     *
     * @return HttpConnectionInterface|null The oldest idle connection.
     */
    public function getFirst(): ?HttpConnectionInterface;

    /**
     * Add a connection to the back (newest end) of the queue.
     *
     * @return void
     */
    public function offerLast(HttpConnectionInterface $connection): void;

    /**
     * Remove a specific connection from the pool.
     *
     * @return bool True if the connection was found and removed.
     */
    public function remove(HttpConnectionInterface $connection): bool;

    /**
     * Number of connections in the pool.
     */
    public function count(): int;

    /**
     * Whether the pool is empty.
     */
    public function isEmpty(): bool;

    /**
     * Remove and close connections that have been idle longer
     * than the given timeout.
     *
     * @param float $idleTimeout Idle timeout in seconds.
     * @return list<HttpConnectionInterface> Pruned connections.
     */
    public function prune(float $idleTimeout): array;

    /**
     * Close all connections and empty the pool.
     *
     * @return void
     */
    public function clear(): void;
}
