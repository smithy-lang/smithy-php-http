<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

/**
 * Store reusable HTTP connections in FIFO order.
 *
 * @internal
 */
final class FifoConnectionPool implements ConnectionPoolInterface
{
    /**
     * @var \SplQueue<array{
     *     connection: HttpConnectionInterface,
     *     returnedAt: float
     * }>
     */
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

        $entry = $this->queue->dequeue();
        $this->size--;

        return $entry['connection'];
    }

    /** @inheritDoc */
    public function offerLast(HttpConnectionInterface $connection): void
    {
        $this->queue->enqueue([
            'connection' => $connection,
            'returnedAt' => \microtime(true)
        ]);
        $this->size++;
    }

    /** @inheritDoc */
    public function remove(HttpConnectionInterface $connection): bool
    {
        $newQueue = new \SplQueue();
        $found = false;

        while (!$this->queue->isEmpty()) {
            $entry = $this->queue->dequeue();

            if (!$found && $entry['connection'] === $connection) {
                $found = true;
                $this->size--;

                continue;
            }

            $newQueue->enqueue($entry);
        }

        $this->queue = $newQueue;

        return $found;
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

    /** @inheritDoc */
    public function prune(float $idleTimeout): array
    {
        $now = \microtime(true);
        $pruned = [];
        $newQueue = new \SplQueue();

        while (!$this->queue->isEmpty()) {
            $entry = $this->queue->dequeue();

            if (($now - $entry['returnedAt']) > $idleTimeout) {
                $connection = $entry['connection'];
                $connection->close();
                $pruned[] = $connection;
                $this->size--;
            } else {
                $newQueue->enqueue($entry);
            }
        }

        $this->queue = $newQueue;

        return $pruned;
    }

    /** @inheritDoc */
    public function clear(): void
    {
        while (!$this->queue->isEmpty()) {
            $entry = $this->queue->dequeue();
            $entry['connection']->close();
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
