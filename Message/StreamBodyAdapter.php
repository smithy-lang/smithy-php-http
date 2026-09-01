<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Adapt a generic PSR-7 stream to the body stream contract.
 */
final class StreamBodyAdapter implements RequestStreamInterface
{
    private int $startOffset;
    private ?int $length;
    private bool $started = false;
    private bool $fullyConsumed = false;

    private function __construct(private readonly StreamInterface $inner)
    {
        $seekable = $inner->isSeekable();
        $this->startOffset = $seekable ? $inner->tell() : 0;
        $size = $inner->getSize();
        if ($size !== null && $seekable) {
            $size = \max(0, $size - $this->startOffset);
        }
        $this->length = $size;
    }

    /**
     * Wrap a PSR-7 stream in the body stream contract.
     */
    public static function wrap(StreamInterface $stream): RequestStreamInterface
    {
        if ($stream instanceof RequestStreamInterface) {
            return $stream;
        }

        return new self($stream);
    }

    /**
     * Return the full inner stream contents as a string.
     */
    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /**
     * Close the inner stream.
     *
     * @return void
     */
    public function close(): void
    {
        $this->inner->close();
    }

    /**
     * Detach the inner stream resource.
     *
     * @return resource|null
     */
    public function detach()
    {
        return $this->inner->detach();
    }

    /**
     * Get the stream size.
     */
    public function getSize(): ?int
    {
        return $this->length();
    }

    /**
     * Get the body length when it is known.
     */
    public function length(): ?int
    {
        return $this->length;
    }

    /**
     * Get the current stream offset.
     */
    public function tell(): int
    {
        return $this->inner->tell();
    }

    /**
     * Determine whether the inner stream is at EOF.
     */
    public function eof(): bool
    {
        return $this->inner->eof();
    }

    /**
     * Determine whether the inner stream can seek.
     */
    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    /**
     * Seek the inner stream.
     *
     * @return void
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);

        if (!$this->started && $this->inner->isSeekable()) {
            $this->startOffset = $this->inner->tell();
            $size = $this->inner->getSize();
            if ($size !== null) {
                $size = \max(0, $size - $this->startOffset);
            }
            $this->length = $size;
            $this->fullyConsumed = false;

            return;
        }

        $this->updateConsumedState();
    }

    /**
     * Rewind the inner stream.
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->inner->rewind();
        $this->fullyConsumed = false;
    }

    /**
     * Rewind the inner stream to its retry start offset.
     *
     * @return void
     */
    public function rewindForRetry(): void
    {
        if (!$this->inner->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable.');
        }

        $this->inner->seek($this->startOffset);
        $this->started = false;
        $this->fullyConsumed = false;
    }

    /**
     * Determine whether the inner stream is writable.
     */
    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    /**
     * Write data to the inner stream.
     */
    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    /**
     * Determine whether the inner stream is readable.
     */
    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    /**
     * Read bytes from the inner stream.
     */
    public function read(int $length): string
    {
        $data = $this->inner->read($length);
        if ($data !== '') {
            $this->started = true;
        } elseif ($this->length === null && $this->started) {
            $this->fullyConsumed = true;

            return $data;
        }
        $this->updateConsumedState();

        return $data;
    }

    /**
     * Read remaining contents from the inner stream.
     */
    public function getContents(): string
    {
        $contents = $this->inner->getContents();
        if ($contents !== '') {
            $this->started = true;
        }
        if ($this->length === null) {
            $this->fullyConsumed = true;

            return $contents;
        }
        $this->updateConsumedState();

        return $contents;
    }

    /**
     * Get inner stream metadata.
     *
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    /**
     * Compute a hash of the body content.
     */
    public function hash(string $algo = 'sha256', bool $raw = false): string
    {
        if ($this->inner instanceof RequestStreamInterface) {
            return $this->inner->hash($algo, $raw);
        }

        if (!$this->inner->isSeekable()) {
            throw new \RuntimeException(
                'Cannot hash a non-seekable generic stream.'
            );
        }

        $position = $this->inner->tell();
        $this->inner->seek($this->startOffset);

        $ctx = \hash_init($algo);
        while (!$this->inner->eof()) {
            $chunk = $this->inner->read(65536);
            if ($chunk === '') {
                break;
            }
            \hash_update($ctx, $chunk);
        }
        $hash = \hash_final($ctx, $raw);

        $this->inner->seek($position);

        return $hash;
    }

    /**
     * Determine whether the body can be replayed.
     */
    public function isReplayable(): bool
    {
        return $this->inner instanceof RequestStreamInterface
            ? $this->inner->isReplayable()
            : $this->inner->isSeekable();
    }

    /**
     * Determine whether the body is still available.
     */
    public function isAvailable(): bool
    {
        return $this->inner instanceof RequestStreamInterface
            ? $this->inner->isAvailable()
            : !$this->started || $this->inner->isSeekable();
    }

    /**
     * Determine whether the stream has started.
     */
    public function started(): bool
    {
        return $this->started;
    }

    /**
     * Determine whether the stream is fully consumed.
     */
    public function fullyConsumed(): bool
    {
        return $this->fullyConsumed;
    }

    private function updateConsumedState(): void
    {
        if ($this->length === null) {
            if (!$this->started) {
                $this->fullyConsumed = false;
            }

            return;
        }

        $this->fullyConsumed
            = ($this->inner->tell() - $this->startOffset) >= $this->length;
    }
}
