<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Smithy\Async\EventLoopInterface;

/**
 * Buffer an incrementally produced request body.
 *
 * Producers write chunks and call end() when complete. Consumers can
 * subscribe to readiness. Writes suspend cooperatively while the buffer is
 * full.
 */
final class AsyncRequestStream implements AsyncRequestStreamInterface
{
    private const DEFAULT_HIGH_WATER_MARK = 64 * 1024;

    /** @var \SplQueue<string> */
    private \SplQueue $chunks;
    private ?string $currentChunk = null;
    private int $currentChunkOffset = 0;
    private int $bufferedBytes = 0;
    private int $position = 0;
    private bool $started = false;
    private AsyncRequestStreamState $state =
        AsyncRequestStreamState::OPEN;
    private bool $discarded = false;
    private bool $consumerDetached = false;
    private ?\Throwable $failure = null;
    /** @var array<int, \Closure(): void> */
    private array $subscribers = [];
    private int $nextSubscriptionId = 0;
    private ?\Closure $writer = null;
    private ?\Closure $writerThrow = null;
    private ?\Throwable $writerFailure = null;
    private int $pendingWriteLength = 0;
    private bool $writerResumeScheduled = false;

    /**
     * @param EventLoopInterface $eventLoop Event loop used to suspend
     *                                      producers.
     * @param int|null $expectedLength Exact body length when known.
     * @param int $highWaterMark Maximum queued bytes before producer
     *                           suspension.
     *
     * @throws \InvalidArgumentException If a length or buffer size is invalid.
     */
    public function __construct(
        private readonly EventLoopInterface $eventLoop,
        private readonly ?int $expectedLength = null,
        private readonly int $highWaterMark =
            self::DEFAULT_HIGH_WATER_MARK
    )
    {
        if ($expectedLength !== null && $expectedLength < 0) {
            throw new \InvalidArgumentException(
                'Expected body length must not be negative.'
            );
        }

        if ($highWaterMark < 1) {
            throw new \InvalidArgumentException(
                'Request buffer size must be positive.'
            );
        }

        $this->chunks = new \SplQueue();
    }

    /**
     * Read all currently buffered bytes.
     */
    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Discard buffered bytes and end the stream.
     *
     * @return void
     */
    public function close(): void
    {
        $this->markClosed();
    }

    /**
     * Close the stream and return no resource.
     *
     * @return null
     */
    public function detach()
    {
        $this->close();

        return null;
    }

    /**
     * Get the expected body length.
     */
    public function getSize(): ?int
    {
        return $this->length();
    }

    /**
     * Get the expected body length.
     */
    public function length(): ?int
    {
        return $this->expectedLength;
    }

    /**
     * Get the number of consumed bytes.
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * Determine whether the producer ended and all bytes were consumed.
     */
    public function eof(): bool
    {
        return (
                $this->state === AsyncRequestStreamState::ENDED
                || $this->state === AsyncRequestStreamState::CLOSED
            )
            && $this->failure === null
            && $this->bufferedBytes === 0;
    }

    /**
     * Determine whether the stream supports seeking.
     */
    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Reject attempts to seek the producer stream.
     *
     * @return void
     * @throws \RuntimeException Always.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException(
            'Async request streams are not seekable.'
        );
    }

    /**
     * Reject attempts to rewind the producer stream.
     *
     * @return void
     * @throws \RuntimeException Always.
     */
    public function rewind(): void
    {
        throw new \RuntimeException(
            'Async request streams are not seekable.'
        );
    }

    /**
     * Reject attempts to replay the producer stream.
     *
     * @return void
     * @throws \RuntimeException Always.
     */
    public function rewindForRetry(): void
    {
        throw new \RuntimeException(
            'Async request streams cannot be replayed.'
        );
    }

    /**
     * Determine whether the producer can write more bytes.
     */
    public function isWritable(): bool
    {
        return $this->isProducerOpen();
    }

    /**
     * Append bytes and notify a waiting consumer.
     *
     * Suspend when the chunk exceeds the bounded queue. Accept one oversized
     * chunk when the queue is empty.
     *
     * @throws \RuntimeException If writing stalls.
     */
    public function write(string $string): int
    {
        if (!$this->isWritable()) {
            throw new \RuntimeException(
                'Async request stream is no longer writable.'
            );
        }

        if ($string === '') {
            return 0;
        }

        $length = \strlen($string);
        $this->assertWriteWithinExpectedLength($length);
        while (!$this->canAcceptWrite($length)) {
            $this->awaitWriteCapacity($length);

            if (!$this->isWritable()) {
                throw new \RuntimeException(
                    'Async request stream is no longer writable.'
                );
            }
        }

        $wasReady = $this->isReady();
        $this->chunks->enqueue($string);
        $this->bufferedBytes += $length;

        if (!$wasReady) {
            $this->notifyReady();
        }

        return $length;
    }

    /**
     * Determine whether the consumer can read the stream.
     */
    public function isReadable(): bool
    {
        return $this->state !== AsyncRequestStreamState::CLOSED;
    }

    /**
     * Read up to the requested number of buffered bytes.
     */
    public function read(int $length): string
    {
        $this->ensureReadable();

        if ($length < 0) {
            throw new \InvalidArgumentException(
                'Read length must be non-negative.'
            );
        }

        if ($length === 0) {
            return '';
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->bufferedBytes === 0) {
            return '';
        }

        $bytesToRead = \min($length, $this->bufferedBytes);
        $remaining = $bytesToRead;
        $parts = [];

        while ($remaining > 0) {
            if ($this->currentChunk === null) {
                $this->currentChunk = $this->chunks->dequeue();
                $this->currentChunkOffset = 0;
            }

            $available = \strlen($this->currentChunk)
                - $this->currentChunkOffset;
            $take = \min($remaining, $available);

            if ($this->currentChunkOffset === 0
                && $take === $available
            ) {
                $parts[] = $this->currentChunk;
            } else {
                $parts[] = \substr(
                    $this->currentChunk,
                    $this->currentChunkOffset,
                    $take
                );
            }

            $remaining -= $take;
            $this->currentChunkOffset += $take;

            if ($this->currentChunkOffset
                    === \strlen($this->currentChunk)
            ) {
                $this->currentChunk = null;
                $this->currentChunkOffset = 0;
            }
        }

        $chunk = \count($parts) === 1
            ? $parts[0]
            : \implode('', $parts);
        $this->bufferedBytes -= $bytesToRead;
        $this->position += $bytesToRead;
        $this->started = true;
        $this->scheduleWriterResume();

        return $chunk;
    }

    /**
     * Read all currently buffered bytes.
     */
    public function getContents(): string
    {
        $this->ensureReadable();

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->bufferedBytes === 0) {
            return '';
        }

        return $this->read($this->bufferedBytes);
    }

    /**
     * Get stream metadata.
     *
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /**
     * Reject hashing an async producer stream.
     *
     * @throws \RuntimeException Always.
     */
    public function hash(string $algo = 'sha256', bool $raw = false): string
    {
        throw new \RuntimeException(
            'Async request streams cannot be hashed.'
        );
    }

    /** @inheritDoc */
    public function isReplayable(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function isAvailable(): bool
    {
        return $this->state !== AsyncRequestStreamState::CLOSED
            && !$this->started
            && $this->failure === null;
    }

    /**
     * Determine whether bytes have been consumed.
     */
    public function started(): bool
    {
        return $this->started;
    }

    /**
     * Determine whether the ended stream was fully consumed.
     */
    public function fullyConsumed(): bool
    {
        return !$this->discarded && $this->eof();
    }

    /**
     * Get the number of queued bytes.
     */
    public function bufferedBytes(): int
    {
        return $this->bufferedBytes;
    }

    /**
     * Determine whether a read can return data, EOF, or an error.
     */
    public function isReady(): bool
    {
        return $this->bufferedBytes > 0
            || $this->state === AsyncRequestStreamState::ENDED
            || $this->state === AsyncRequestStreamState::CLOSED
            || $this->failure !== null;
    }

    /**
     * Subscribe to transitions from unavailable to readable.
     *
     * The callback receives no arguments. It is a readiness signal only:
     * after it runs, a read can return data, EOF, or an error.
     *
     * @param \Closure(): void $callback
     *
     * @return int Subscription identifier.
     */
    public function subscribe(\Closure $callback): int
    {
        $subscriptionId = ++$this->nextSubscriptionId;
        $this->subscribers[$subscriptionId] = $callback;

        return $subscriptionId;
    }

    /**
     * Remove a readiness subscription.
     *
     * @return void
     */
    public function unsubscribe(int $subscriptionId): void
    {
        unset($this->subscribers[$subscriptionId]);
    }

    /**
     * Signal that the active consumer will no longer drain the body.
     *
     * @return void
     */
    public function detachConsumer(): void
    {
        if ($this->consumerDetached) {
            return;
        }

        $this->consumerDetached = true;

        if ($this->writer !== null) {
            $this->writerFailure = new \RuntimeException(
                'Async request stream consumer detached while a write '
                    . 'was waiting for capacity.'
            );
            $this->scheduleWriterResume();
        }
    }

    /**
     * Mark the producer as complete.
     *
     * @return void
     */
    public function end(): void
    {
        if ($this->state !== AsyncRequestStreamState::OPEN) {
            return;
        }

        if ($this->expectedLength !== null
            && $this->producedBytes() < $this->expectedLength
        ) {
            $producedBytes = $this->producedBytes();
            $failure = new \LengthException(
                'Async request stream ended after '
                    . "{$producedBytes} bytes, expected "
                    . "{$this->expectedLength} bytes."
            );
            $this->markFailed($failure);

            throw $failure;
        }

        $this->markEnded();
    }

    /**
     * Fail future reads.
     *
     * @return void
     */
    public function fail(\Throwable $failure): void
    {
        $this->markFailed($failure);
    }

    /**
     * Determine whether producer writes may still be accepted.
     */
    private function isProducerOpen(): bool
    {
        return $this->state === AsyncRequestStreamState::OPEN
            && !$this->consumerDetached;
    }

    /**
     * Mark producer completion and notify blocked consumers.
     *
     * @return void
     */
    private function markEnded(): void
    {
        if ($this->state !== AsyncRequestStreamState::OPEN) {
            return;
        }

        $wasReady = $this->isReady();
        $this->state = AsyncRequestStreamState::ENDED;
        $this->finishNotifications($wasReady);
        $this->scheduleWriterResume();
    }

    /**
     * Mark producer failure and notify blocked consumers.
     *
     * @return void
     */
    private function markFailed(\Throwable $failure): void
    {
        if ($this->state !== AsyncRequestStreamState::OPEN) {
            return;
        }

        $wasReady = $this->isReady();
        $this->failure = $failure;
        $this->state = AsyncRequestStreamState::FAILED;
        $this->finishNotifications($wasReady);
        $this->scheduleWriterResume();
    }

    /**
     * Mark closure, discard queued bytes, and release waiters.
     *
     * @return void
     */
    private function markClosed(): void
    {
        if ($this->state === AsyncRequestStreamState::CLOSED) {
            return;
        }

        $wasReady = $this->isReady();
        if ($this->state !== AsyncRequestStreamState::ENDED
            || $this->bufferedBytes > 0
        ) {
            $this->discarded = true;
        }

        $this->consumerDetached = true;
        $this->chunks = new \SplQueue();
        $this->currentChunk = null;
        $this->currentChunkOffset = 0;
        $this->bufferedBytes = 0;
        $this->state = AsyncRequestStreamState::CLOSED;
        $this->finishNotifications($wasReady);
        $this->scheduleWriterResume();
    }

    /**
     * Determine whether the next write fits the bounded queue.
     */
    private function canAcceptWrite(int $length): bool
    {
        return $this->bufferedBytes === 0
            || $length <= $this->highWaterMark - $this->bufferedBytes;
    }

    /**
     * Validate that the next write fits the declared length.
     *
     * @return void
     */
    private function assertWriteWithinExpectedLength(int $length): void
    {
        if ($this->expectedLength === null) {
            return;
        }

        if ($this->producedBytes() + $length <= $this->expectedLength) {
            return;
        }

        $failure = new \LengthException(
            'Async request stream write exceeds expected length of '
                . "{$this->expectedLength} bytes."
        );
        $this->fail($failure);

        throw $failure;
    }

    /**
     * Get bytes produced by the writer, whether read or still buffered.
     */
    private function producedBytes(): int
    {
        return $this->position + $this->bufferedBytes;
    }

    /**
     * Reject read operations after closure.
     *
     * @return void
     */
    private function ensureReadable(): void
    {
        if ($this->state === AsyncRequestStreamState::CLOSED) {
            throw new \RuntimeException('Async request stream is closed.');
        }
    }

    /**
     * Suspend one producer until its next chunk fits.
     *
     * @return void
     */
    private function awaitWriteCapacity(int $length): void
    {
        if ($this->writer !== null) {
            throw new \RuntimeException(
                'Concurrent async body stream writes are not supported.'
            );
        }

        $this->eventLoop->suspend(
            function (
                callable $resume,
                callable $throw
            ) use ($length): void
            {
                if (!$this->isWritable()
                    || $this->canAcceptWrite($length)
                ) {
                    $resume();

                    return;
                }

                $this->pendingWriteLength = $length;
                $this->writer = $resume(...);
                $this->writerThrow = $throw(...);
            }
        );
    }

    /**
     * Resume a blocked producer after the consumer releases capacity.
     *
     * @return void
     */
    private function scheduleWriterResume(): void
    {
        if ($this->writer === null
            || $this->writerResumeScheduled
            || (
                $this->writerFailure === null
                && $this->isWritable()
                && !$this->canAcceptWrite($this->pendingWriteLength)
            )
        ) {
            return;
        }

        $this->writerResumeScheduled = true;
        $this->eventLoop->defer(function (): void {
            $this->writerResumeScheduled = false;

            if ($this->writer === null
                || (
                    $this->writerFailure === null
                    && $this->isWritable()
                    && !$this->canAcceptWrite(
                        $this->pendingWriteLength
                    )
                )
            ) {
                return;
            }

            $writer = $this->writer;
            $writerThrow = $this->writerThrow;
            $writerFailure = $this->writerFailure;
            $this->writer = null;
            $this->writerThrow = null;
            $this->writerFailure = null;
            $this->pendingWriteLength = 0;

            if ($writerFailure !== null) {
                \assert($writerThrow !== null);
                $writerThrow($writerFailure);

                return;
            }

            $writer();
        });
    }

    /**
     * Notify readiness subscribers.
     *
     * @return void
     */
    private function notifyReady(): void
    {
        $firstError = null;
        foreach ($this->subscribers as $subscriber) {
            try {
                $subscriber();
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }

        if ($firstError !== null) {
            throw $firstError;
        }
    }

    /**
     * Notify a waiting consumer and release subscriber closures.
     *
     * @return void
     */
    private function finishNotifications(bool $wasReady): void
    {
        try {
            if (!$wasReady) {
                $this->notifyReady();
            }
        } finally {
            $this->subscribers = [];
            $this->scheduleWriterResume();
        }
    }
}
