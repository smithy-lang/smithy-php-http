<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 StreamInterface implementation backed by a PHP resource.
 *
 * Wraps a PHP stream resource (fopen handle, php://temp, php://memory,
 * etc.) and provides the full StreamInterface contract.
 *
 * For string bodies, use Stream::create($string) which writes the
 * string into php://memory and returns a seekable, readable stream.
 */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private ?int $size = null;

    /**
     * @param resource $resource A PHP stream resource.
     */
    public function __construct($resource)
    {
        if (!self::isStreamResource($resource)) {
            throw new \InvalidArgumentException(
                'Stream requires a valid PHP stream resource.'
            );
        }

        $this->resource = $resource;
    }

    /**
     * Create a stream from a string body.
     */
    public static function create(string $content = ''): self
    {
        $resource = \fopen('php://memory', 'r+');
        if ($resource === false) {
            throw new \RuntimeException(
                'Unable to create an in-memory stream.'
            );
        }

        if ($content !== '') {
            \fwrite($resource, $content);
            \rewind($resource);
        }

        return new self($resource);
    }

    /**
     * Create an empty stream.
     */
    public static function empty(): self
    {
        return self::create('');
    }

    /**
     * @inheritDoc
     *
     * This reads the stream contents into memory as required by PSR-7.
     * Large bodies should be consumed incrementally with read().
     */
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    /** @inheritDoc */
    public function close(): void
    {
        if ($this->resource !== null) {
            $resource = $this->detach();
            if (\is_resource($resource)) {
                \fclose($resource);
            }
        }
    }

    /** @inheritDoc */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->size = null;

        return $resource;
    }

    /** @inheritDoc */
    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        if ($this->size !== null) {
            return $this->size;
        }

        $stats = \fstat($this->resource);
        if ($stats !== false && isset($stats['size'])) {
            $this->size = $stats['size'];

            return $this->size;
        }

        return null;
    }

    /** @inheritDoc */
    public function tell(): int
    {
        $resource = $this->ensureResource();

        $position = \ftell($resource);
        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position.');
        }

        return $position;
    }

    /** @inheritDoc */
    public function eof(): bool
    {
        return $this->resource === null || \feof($this->resource);
    }

    /** @inheritDoc */
    public function isSeekable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $meta = \stream_get_meta_data($this->resource);

        return $meta['seekable'];
    }

    /** @inheritDoc */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $resource = $this->ensureResource();

        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable.');
        }

        if (\fseek($resource, $offset, $whence) === -1) {
            throw new \RuntimeException(
                "Unable to seek to position {$offset}."
            );
        }

        $this->size = null;
    }

    /** @inheritDoc */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /** @inheritDoc */
    public function isWritable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $meta = \stream_get_meta_data($this->resource);
        $mode = $meta['mode'];

        return \str_contains($mode, 'w')
            || \str_contains($mode, 'a')
            || \str_contains($mode, 'x')
            || \str_contains($mode, 'c')
            || \str_contains($mode, '+');
    }

    /** @inheritDoc */
    public function write(string $string): int
    {
        $resource = $this->ensureResource();

        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable.');
        }

        $bytes = \fwrite($resource, $string);
        if ($bytes === false) {
            throw new \RuntimeException('Unable to write to stream.');
        }

        $this->size = null;

        return $bytes;
    }

    /** @inheritDoc */
    public function isReadable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $meta = \stream_get_meta_data($this->resource);
        $mode = $meta['mode'];

        return \str_contains($mode, 'r') || \str_contains($mode, '+');
    }

    /** @inheritDoc */
    public function read(int $length): string
    {
        $resource = $this->ensureResource();

        if ($length < 0) {
            throw new \InvalidArgumentException(
                'Read length must be non-negative.'
            );
        }

        if ($length === 0) {
            return '';
        }

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable.');
        }

        $data = \fread($resource, $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read from stream.');
        }

        return $data;
    }

    /** @inheritDoc */
    public function getContents(): string
    {
        $resource = $this->ensureResource();

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable.');
        }

        $contents = \stream_get_contents($resource);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents.');
        }

        return $contents;
    }

    /** @inheritDoc */
    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = \stream_get_meta_data($this->resource);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    /**
     * Return the open resource.
     *
     * @return resource
     * @throws \RuntimeException When the stream has been detached.
     */
    private function ensureResource()
    {
        return $this->resource
            ?? throw new \RuntimeException('Stream has been detached.');
    }

    /**
     * @phpstan-assert-if-true resource $resource
     */
    private static function isStreamResource(mixed $resource): bool
    {
        return \is_resource($resource)
            && \get_resource_type($resource) === 'stream';
    }
}
