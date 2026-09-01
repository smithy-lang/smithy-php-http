<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Smithy\Async\EventLoopInterface;

/**
 * Stream a response body through a bounded producer buffer.
 *
 * Reads suspend cooperatively while the producer is active and no bytes are
 * available. The transport pauses production when append() reaches the high
 * water mark and resumes it after the consumer drains to the low water mark.
 */
final class StreamingResponseStream implements ResponseStreamInterface
{
    private readonly int $lowWaterMark;
    /** @var \SplQueue<string> */
    private \SplQueue $chunks;
    private ?string $currentChunk = null;
    private int $currentChunkOffset = 0;
    private int $bufferedBytes = 0;
    private int $blockedChunkLength = 0;
    private int $position = 0;
    private bool $started = false;
    private StreamingResponseStreamState $state =
        StreamingResponseStreamState::OPEN;
    private bool $discarding = false;
    private ?\Throwable $failure = null;
    private ?\Closure $reader = null;
    private ?\Closure $terminalWaiter = null;
    private ?\Closure $onSpaceAvailable;
    private ?\Closure $onClose;

    /**
     * @param \Closure(): void $onSpaceAvailable Notify the producer after
     *                                           bytes are consumed.
     * @param \Closure(): void $onClose Notify the producer when the body
     *                                  closes before completion.
     *
     * @throws \InvalidArgumentException If the high water mark is zero or negative.
     */
    public function __construct(
        private readonly EventLoopInterface $eventLoop,
        private readonly int $highWaterMark,
        \Closure $onSpaceAvailable,
        \Closure $onClose
    )
    {
        if ($highWaterMark < 1) {
            throw new \InvalidArgumentException(
                'Response buffer size must be positive.'
            );
        }

        $this->chunks = new \SplQueue();
        $this->lowWaterMark = \intdiv($highWaterMark, 2);
        $this->onSpaceAvailable = $onSpaceAvailable;
        $this->onClose = $onClose;
    }

    /**
     * Read the remaining response and suppress stored failures.
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
     * Discard buffered bytes and abort active production.
     *
     * @return void
     */
    public function close(): void
    {
        $this->markClosed();
    }

    /**
     * Close the body and return no resource.
     *
     * @return null
     */
    public function detach()
    {
        $this->close();

        return null;
    }

    /**
     * Streaming response length is not known.
     */
    public function getSize(): ?int
    {
        return $this->length();
    }

    /**
     * Streaming response length is not known.
     */
    public function length(): ?int
    {
        return null;
    }

    /**
     * Get the number of consumed response bytes.
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * Determine whether the response ended and all buffered bytes were read.
     */
    public function eof(): bool
    {
        return $this->state === StreamingResponseStreamState::CLOSED
            || (
                $this->state === StreamingResponseStreamState::ENDED
                && $this->failure === null
                && $this->bufferedBytes === 0
            );
    }

    /**
     * Determine whether the response can be sought.
     */
    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Reject attempts to seek the response.
     *
     * @return void
     * @throws \RuntimeException Always.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException(
            'Streamed response bodies are not seekable.'
        );
    }

    /**
     * Reject attempts to rewind the response.
     *
     * @return void
     * @throws \RuntimeException Always.
     */
    public function rewind(): void
    {
        throw new \RuntimeException(
            'Streamed response bodies are not seekable.'
        );
    }

    /**
     * Reject attempts to replay the response.
     *
     * @return void
     * @throws \RuntimeException Always.
     */
    public function rewindForRetry(): void
    {
        throw new \RuntimeException(
            'Streamed response bodies cannot be replayed.'
        );
    }

    /**
     * Determine whether the response can be written by consumers.
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Reject consumer writes.
     *
     * @throws \RuntimeException Always.
     */
    public function write(string $string): int
    {
        throw new \RuntimeException(
            'Streamed response bodies are not writable.'
        );
    }

    /**
     * Determine whether the response can be read.
     */
    public function isReadable(): bool
    {
        return $this->state !== StreamingResponseStreamState::CLOSED;
    }

    /**
     * Read response bytes, suspending until data or a terminal state is ready.
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException(
                'Read length must be non-negative.'
            );
        }

        $this->ensureReadable();
        if ($length === 0) {
            return '';
        }

        if ($this->discarding) {
            throw new \RuntimeException(
                'Response body discard is already in progress.'
            );
        }

        while ($this->bufferedBytes === 0
            && $this->state === StreamingResponseStreamState::OPEN
            && $this->failure === null
        ) {
            $this->awaitReadable();
            $this->ensureReadable();
        }

        if ($this->bufferedBytes === 0) {
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return '';
        }

        if ($this->currentChunk === null) {
            $this->currentChunk = $this->chunks->dequeue();
            $this->currentChunkOffset = 0;
        }

        $available = \strlen($this->currentChunk)
            - $this->currentChunkOffset;
        $bytesToRead = \min($length, $available);

        if ($this->currentChunkOffset === 0
            && $bytesToRead === $available
        ) {
            $chunk = $this->currentChunk;
            $this->currentChunk = null;
        } else {
            $chunk = \substr(
                $this->currentChunk,
                $this->currentChunkOffset,
                $bytesToRead
            );
            $this->currentChunkOffset += $bytesToRead;

            if ($this->currentChunkOffset
                    === \strlen($this->currentChunk)
            ) {
                $this->currentChunk = null;
                $this->currentChunkOffset = 0;
            }
        }

        $this->bufferedBytes -= $bytesToRead;
        $this->position += $bytesToRead;
        $this->started = true;
        $this->notifySpaceAvailable();

        return $chunk;
    }

    /**
     * Discard unread bytes and wait for active production to complete.
     *
     * @return void
     */
    public function discard(): void
    {
        $this->ensureReadable();

        if ($this->discarding || $this->reader !== null) {
            throw new \RuntimeException(
                'Concurrent response body operations are not supported.'
            );
        }

        $discarded = $this->bufferedBytes;
        $this->position += $discarded;
        $this->started = $this->started || $discarded > 0;
        $this->clearBuffer();
        $this->discarding = true;
        $this->notifySpaceAvailable();

        try {
            while ($this->isProducerOpen()) {
                $this->awaitTerminal();
            }

            if ($this->failure !== null) {
                throw $this->failure;
            }

            $this->ensureReadable();
        } finally {
            $this->discarding = false;
        }
    }

    /**
     * Read the response through EOF.
     */
    public function getContents(): string
    {
        $this->ensureReadable();
        $parts = [];

        while (!$this->eof()) {
            $parts[] = $this->read($this->highWaterMark);
        }

        return \implode('', $parts);
    }

    /**
     * Get response stream metadata.
     *
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /**
     * Reject hashing an incrementally delivered response.
     *
     * @throws \RuntimeException Always.
     */
    public function hash(string $algo = 'sha256', bool $raw = false): string
    {
        throw new \RuntimeException(
            'Streamed response bodies cannot be hashed.'
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
        return $this->state !== StreamingResponseStreamState::CLOSED
            && !$this->started;
    }

    /**
     * Determine whether response bytes have been consumed.
     */
    public function started(): bool
    {
        return $this->started;
    }

    /**
     * Determine whether the response ended and all bytes were consumed.
     */
    public function fullyConsumed(): bool
    {
        return $this->eof()
            && $this->state !== StreamingResponseStreamState::CLOSED;
    }

    /**
     * Get the number of currently buffered bytes.
     */
    public function bufferedBytes(): int
    {
        return $this->bufferedBytes;
    }

    /**
     * Determine whether a producer chunk fits within the high water mark.
     */
    public function canAppend(int $length): bool
    {
        return $this->isProducerOpen()
            && (
                $this->discarding
                || $this->bufferedBytes === 0
                || $this->bufferedBytes() + $length
                    <= $this->highWaterMark
            );
    }

    /**
     * Determine whether a paused producer can resume.
     */
    public function canResume(int $length): bool
    {
        return $this->canAppend($length)
            && (
                $this->discarding
                || $this->bufferedBytes <= $this->lowWaterMark
            );
    }

    /**
     * Append bytes supplied by the response transport.
     */
    public function append(string $chunk): bool
    {
        if ($chunk === '') {
            return true;
        }

        $length = \strlen($chunk);
        if (!$this->canAppend($length)) {
            if ($this->isProducerOpen()) {
                $this->blockedChunkLength = $length;
            }

            return false;
        }

        if ($this->discarding) {
            $this->position += $length;
            $this->started = true;

            return true;
        }

        $this->chunks->enqueue($chunk);
        $this->bufferedBytes += $length;
        $this->wakeReader();

        return true;
    }

    /**
     * Mark response production as complete.
     *
     * @return void
     */
    public function end(): void
    {
        $this->markEnded();
    }

    /**
     * Fail reads after already-buffered bytes are consumed.
     *
     * @return void
     */
    public function fail(\Throwable $failure): void
    {
        $this->markFailed($failure);
    }

    /**
     * Abort active production when the body leaves scope.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Suspend until data, EOF, failure, or closure becomes observable.
     *
     * @return void
     */
    private function awaitReadable(): void
    {
        if ($this->reader !== null) {
            throw new \RuntimeException(
                'Concurrent response body reads are not supported.'
            );
        }

        $this->eventLoop->suspend(
            function (
                callable $resume,
                callable $throw
            ): void
            {
                if ($this->bufferedBytes > 0
                    || $this->state !== StreamingResponseStreamState::OPEN
                    || $this->failure !== null
                ) {
                    $resume();

                    return;
                }

                $this->reader = $resume(...);
            }
        );
    }

    /**
     * Suspend until production reaches a terminal state.
     *
     * @return void
     */
    private function awaitTerminal(): void
    {
        if ($this->terminalWaiter !== null) {
            throw new \RuntimeException(
                'Concurrent response body discards are not supported.'
            );
        }

        $this->eventLoop->suspend(
            function (
                callable $resume,
                callable $throw
            ): void
            {
                if (!$this->isProducerOpen()) {
                    $resume();

                    return;
                }

                $this->terminalWaiter = $resume(...);
            }
        );
    }

    /**
     * Resume a suspended reader.
     *
     * @return void
     */
    private function wakeReader(): void
    {
        $reader = $this->reader;
        $this->reader = null;
        $reader?->__invoke();
    }

    /**
     * Resume readers and discard waiters.
     *
     * @return void
     */
    private function wakeWaiters(): void
    {
        $this->wakeReader();
        $terminalWaiter = $this->terminalWaiter;
        $this->terminalWaiter = null;
        $terminalWaiter?->__invoke();
    }

    /**
     * Notify a blocked producer once its retained chunk can fit.
     *
     * @return void
     */
    private function notifySpaceAvailable(): void
    {
        if ($this->blockedChunkLength === 0
            || !$this->canResume($this->blockedChunkLength)
        ) {
            return;
        }

        $this->blockedChunkLength = 0;
        $this->onSpaceAvailable?->__invoke();
    }

    /**
     * Determine whether the transport may still append bytes.
     */
    private function isProducerOpen(): bool
    {
        return $this->state === StreamingResponseStreamState::OPEN;
    }

    /**
     * Mark normal transport completion and wake waiters.
     *
     * @return void
     */
    private function markEnded(): void
    {
        if (!$this->isProducerOpen()) {
            return;
        }

        $this->state = StreamingResponseStreamState::ENDED;
        $this->blockedChunkLength = 0;
        $this->onSpaceAvailable = null;
        $this->onClose = null;
        $this->wakeWaiters();
    }

    /**
     * Mark transport failure and wake waiters.
     *
     * @return void
     */
    private function markFailed(\Throwable $failure): void
    {
        if (!$this->isProducerOpen()) {
            return;
        }

        $this->failure = $failure;
        $this->state = StreamingResponseStreamState::FAILED;
        $this->blockedChunkLength = 0;
        $this->onSpaceAvailable = null;
        $this->onClose = null;
        $this->wakeWaiters();
    }

    /**
     * Mark closure, discard queued bytes, and abort active production.
     *
     * @return void
     */
    private function markClosed(): void
    {
        if ($this->state === StreamingResponseStreamState::CLOSED) {
            return;
        }

        $producerActive = $this->isProducerOpen();
        $this->state = StreamingResponseStreamState::CLOSED;
        $this->blockedChunkLength = 0;
        $this->clearBuffer();
        $this->wakeWaiters();
        $onClose = $this->onClose;
        $this->onSpaceAvailable = null;
        $this->onClose = null;

        if ($producerActive && $onClose !== null) {
            $onClose();
        }
    }

    /**
     * Release all queued and partially consumed response bytes.
     *
     * @return void
     */
    private function clearBuffer(): void
    {
        $this->chunks = new \SplQueue();
        $this->currentChunk = null;
        $this->currentChunkOffset = 0;
        $this->bufferedBytes = 0;
    }

    /**
     * Reject operations after closure.
     *
     * @return void
     */
    private function ensureReadable(): void
    {
        if ($this->state === StreamingResponseStreamState::CLOSED) {
            throw new \RuntimeException(
                'Streamed response body is closed.'
            );
        }
    }
}
