<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

/**
 * Store reusable HTTP connections in FIFO order.
 *
 * @internal
 */
final class FifoConnectionPool implements ConnectionPoolInterface
{
    /** @var \SplQueue<HttpConnectionInterface> */
    private \SplQueue $queue;

    private int $size = 0;

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    /** @inheritDoc */
    public function getFirst(): ?HttpConnectionInterface
    {
        if ($this->queue->isEmpty()) {
            return null;
        }

        $this->size--;

        return $this->queue->dequeue();
    }

    /** @inheritDoc */
    public function offerLast(HttpConnectionInterface $connection): void
    {
        $this->queue->enqueue($connection);
        $this->size++;
    }

    /** @inheritDoc */
    public function remove(HttpConnectionInterface $connection): bool
    {
        foreach ($this->queue as $index => $queued) {
            if ($queued === $connection) {
                $this->queue->offsetUnset($index);
                $this->size--;

                return true;
            }
        }

        return false;
    }

    /** @inheritDoc */
    public function count(): int
    {
        return $this->size;
    }

    /** @inheritDoc */
    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    /**
     * Remove and close connections idle longer than the timeout.
     *
     * Uses the same lastUsedAt() clock as the connection manager.
     *
     * @return list<HttpConnectionInterface>
     */
    public function prune(float $idleTimeout): array
    {
        $now = \microtime(true);
        $pruned = [];

        for ($remaining = $this->size; $remaining > 0; $remaining--) {
            $connection = $this->queue->dequeue();

            if (($now - $connection->lastUsedAt()) > $idleTimeout) {
                $connection->close();
                $pruned[] = $connection;
                $this->size--;
            } else {
                $this->queue->enqueue($connection);
            }
        }

        return $pruned;
    }

    /** @inheritDoc */
    public function clear(): void
    {
        while (!$this->queue->isEmpty()) {
            $this->queue->dequeue()->close();
        }

        $this->size = 0;
    }

    /**
     * Clear pooled connections.
     */
    public function __destruct()
    {
        $this->clear();
    }
}
