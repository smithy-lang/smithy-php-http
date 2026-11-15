<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * String-backed request stream.
 */
final class StringRequestStream implements RequestStreamInterface
{
    private static ?self $empty = null;

    private int $offset = 0;
    private ?int $length = null;
    private bool $started = false;
    private bool $fullyConsumed;

    /**
     * Create a string-backed stream.
     *
     * An empty stream starts fully consumed, so the shared empty instance never changes state.
     */
    public function __construct(private readonly string $content)
    {
        $this->fullyConsumed = $content === '';
    }

    /**
     * Get the shared empty stream instance.
     */
    public static function empty(): self
    {
        return self::$empty ??= new self('');
    }

    /**
     * Return the full stream content.
     */
    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * Close the stream.
     *
     * @return void
     */
    public function close(): void {}

    /**
     * Detach the underlying resource.
     *
     * @return resource|null
     */
    public function detach()
    {
        return null;
    }

    /**
     * Get the stream size.
     */
    public function getSize(): ?int
    {
        return $this->length();
    }

    /**
     * Get the request length.
     */
    public function length(): int
    {
        return $this->length ??= \strlen($this->content);
    }

    /**
     * Get the current stream offset.
     */
    public function tell(): int
    {
        return $this->offset;
    }

    /**
     * Determine whether the stream is at EOF.
     */
    public function eof(): bool
    {
        return $this->offset >= $this->length();
    }

    /**
     * Determine whether the stream can seek.
     */
    public function isSeekable(): bool
    {
        return true;
    }

    /**
     * Seek to a stream offset.
     *
     * @return void
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $length = $this->length();

        $newOffset = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->offset + $offset,
            SEEK_END => $length + $offset,
            default => throw new \InvalidArgumentException(
                "Invalid whence: {$whence}"
            ),
        };

        if ($newOffset < 0 || $newOffset > $length) {
            throw new \RuntimeException(
                "Unable to seek to position {$newOffset}."
            );
        }

        $this->offset = $newOffset;
        $this->fullyConsumed = $newOffset >= $length;
    }

    /**
     * Rewind the stream.
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->offset = 0;
        $this->fullyConsumed = $this->content === '';
    }

    /**
     * Rewind the stream for retry.
     *
     * @return void
     */
    public function rewindForRetry(): void
    {
        $this->rewind();
        $this->started = false;
    }

    /**
     * Determine whether the stream is writable.
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Write data to the stream.
     */
    public function write(string $string): int
    {
        throw new \RuntimeException('StringRequestStream is not writable.');
    }

    /**
     * Determine whether the stream is readable.
     */
    public function isReadable(): bool
    {
        return true;
    }

    /**
     * Read bytes from the stream.
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException(
                'Read length must be non-negative.'
            );
        }

        $data = \substr($this->content, $this->offset, $length);
        if ($data !== '') {
            $this->started = true;
        }
        $this->offset += \strlen($data);
        $this->fullyConsumed = $this->offset >= $this->length();

        return $data;
    }

    /**
     * Read the remaining stream contents.
     */
    public function getContents(): string
    {
        $contents = \substr($this->content, $this->offset);
        if ($contents !== '') {
            $this->started = true;
        }
        $this->offset = $this->length();
        $this->fullyConsumed = true;

        return $contents;
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
     * Compute a hash of the full stream content.
     */
    public function hash(string $algo = 'sha256', bool $raw = false): string
    {
        return \hash($algo, $this->content, $raw);
    }

    /**
     * Determine whether the stream can be replayed.
     */
    public function isReplayable(): bool
    {
        return true;
    }

    /**
     * Determine whether the stream is available to read or replay.
     */
    public function isAvailable(): bool
    {
        return true;
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
}
