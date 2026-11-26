<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * String-backed response body stream.
 *
 * This is a plain PSR-7 stream, not a request stream. A request that receives it
 * adapts it at its current offset, so a partially read body forwards only its
 * unread bytes.
 */
final class StringResponseStream implements StreamInterface
{
    private static ?self $empty = null;

    private int $offset = 0;

    /**
     * Create a string-backed response stream.
     */
    public function __construct(private readonly string $content) {}

    /**
     * Get the shared empty stream instance.
     *
     * The empty stream has no reachable state other than offset 0, so sharing it is safe.
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
    public function getSize(): int
    {
        return \strlen($this->content);
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
        return $this->offset >= \strlen($this->content);
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
        $length = \strlen($this->content);

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
    }

    /**
     * Rewind the stream.
     *
     * @return void
     */
    public function rewind(): void
    {
        $this->offset = 0;
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
        throw new \RuntimeException('StringResponseStream is not writable.');
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
        $this->offset += \strlen($data);

        return $data;
    }

    /**
     * Read the remaining stream contents.
     */
    public function getContents(): string
    {
        $contents = \substr($this->content, $this->offset);
        $this->offset = \strlen($this->content);

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
}
