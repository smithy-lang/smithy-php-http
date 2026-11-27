<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Resource-backed request stream.
 */
final class ResourceRequestStream implements RequestStreamInterface
{
    /** @var resource|null */
    private $resource;

    /** @var (\Closure(): resource)|null */
    private ?\Closure $resourceOpener = null;

    private ?int $size = null;

    private bool $hasExplicitLength = false;

    private int $startOffset = 0;

    private int $logicalPosition = 0;

    private bool $started = false;

    private bool $fullyConsumed = false;

    /**
     * @param resource|null $resource
     * @param (\Closure(): resource)|null $resourceOpener
     */
    public function __construct(
        $resource,
        ?\Closure $resourceOpener = null,
        ?int $length = null
    )
    {
        if ($length !== null && $length < 0) {
            throw new \InvalidArgumentException(
                'Resource body length must not be negative.'
            );
        }
        $this->hasExplicitLength = $length !== null;

        if ($resourceOpener !== null) {
            if ($resource !== null) {
                throw new \InvalidArgumentException(
                    'A resource and resource opener cannot both be provided.'
                );
            }

            $this->resource = null;
            $this->resourceOpener = $resourceOpener;
            $this->size = $length;

            return;
        }

        if (!self::isStreamResource($resource)) {
            throw new \InvalidArgumentException(
                'ResourceRequestStream requires a valid PHP stream resource.'
            );
        }

        $this->resource = $resource;
        $this->startOffset = self::resourcePosition($resource);
        $this->logicalPosition = $this->startOffset;
        $this->size = $length;
    }

    /**
     * Create a lazily opened, replayable resource request.
     *
     * The opener must return a fresh resource positioned at the logical body
     * start each time it is invoked.
     *
     * @param callable(): resource $openResource
     */
    public static function create(
        callable $openResource,
        ?int $length = null
    ): self
    {
        return new self(
            null,
            $openResource instanceof \Closure
                ? $openResource
                : \Closure::fromCallable($openResource),
            $length
        );
    }

    /**
     * Create a lazily opened, replayable file request.
     */
    public static function fromFile(string $path): self
    {
        $length = @\filesize($path);
        if ($length === false) {
            throw new \RuntimeException(
                "Unable to determine file size: {$path}"
            );
        }

        return self::create(
            static function () use ($path) {
                $resource = \fopen($path, 'rb');
                if ($resource === false) {
                    throw new \RuntimeException(
                        "Unable to open file: {$path}"
                    );
                }

                return $resource;
            },
            $length
        );
    }

    /**
     * Create a temporary resource request containing the provided bytes.
     */
    public static function temp(string $content = ''): self
    {
        $resource = \fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException(
                'Unable to create a temporary stream.'
            );
        }

        if ($content !== '') {
            \fwrite($resource, $content);
            \rewind($resource);
        }

        return new self($resource);
    }

    /** @inheritDoc */
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->seek($this->startOffset);
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    /** @inheritDoc */
    public function close(): void
    {
        $this->resourceOpener = null;
        if ($this->resource !== null) {
            $this->closeCurrentResource();
        }
        $this->size = null;
        $this->hasExplicitLength = false;
    }

    /** @inheritDoc */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        $this->resourceOpener = null;
        $this->size = null;
        $this->hasExplicitLength = false;

        return $resource;
    }

    /** @inheritDoc */
    public function getSize(): ?int
    {
        return $this->length();
    }

    /** @inheritDoc */
    public function length(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if ($this->resource === null) {
            return null;
        }

        if (!$this->isSeekable()) {
            return null;
        }

        $stats = \fstat($this->resource);
        if ($stats === false || !isset($stats['size'])) {
            return null;
        }

        return $this->size = \max(0, $stats['size'] - $this->startOffset);
    }

    /** @inheritDoc */
    public function tell(): int
    {
        $resource = $this->ensureResource();

        $position = \ftell($resource);
        if ($position === false) {
            throw new \RuntimeException(
                'Unable to determine stream position.'
            );
        }

        return $position;
    }

    /** @inheritDoc */
    public function eof(): bool
    {
        if ($this->resource === null) {
            return $this->resourceOpener === null;
        }

        if ($this->remainingLogicalBytes() === 0) {
            return true;
        }

        return \feof($this->resource);
    }

    /** @inheritDoc */
    public function isSeekable(): bool
    {
        if ($this->resource === null && $this->resourceOpener === null) {
            return false;
        }

        $resource = $this->ensureResource();
        $meta = \stream_get_meta_data($resource);

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

        if (!$this->started) {
            $position = \ftell($resource);
            if ($position === false) {
                throw new \RuntimeException(
                    'Unable to determine stream position.'
                );
            }

            $this->startOffset = $position;
            $this->logicalPosition = $position;
            if (!$this->hasExplicitLength) {
                $this->size = null;
            }
            $this->fullyConsumed = false;

            return;
        }

        $this->updateConsumedState();
    }

    /** @inheritDoc */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /** @inheritDoc */
    public function rewindForRetry(): void
    {
        if ($this->resourceOpener !== null) {
            $this->closeCurrentResource();
            $this->startOffset = 0;
            $this->started = false;
            $this->fullyConsumed = false;

            return;
        }

        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable.');
        }

        if (\fseek($this->ensureResource(), $this->startOffset) === -1) {
            throw new \RuntimeException(
                'Unable to rewind stream for retry.'
            );
        }

        $this->logicalPosition = $this->startOffset;
        $this->started = false;
        $this->fullyConsumed = false;
    }

    /** @inheritDoc */
    public function isWritable(): bool
    {
        if ($this->resource === null && $this->resourceOpener === null) {
            return false;
        }

        $resource = $this->ensureResource();
        $meta = \stream_get_meta_data($resource);
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
        $this->hasExplicitLength = false;
        $this->updateConsumedState();

        return $bytes;
    }

    /** @inheritDoc */
    public function isReadable(): bool
    {
        if ($this->resource === null && $this->resourceOpener === null) {
            return false;
        }

        $resource = $this->ensureResource();
        $meta = \stream_get_meta_data($resource);
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

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable.');
        }

        $boundedLength = $this->limitReadLength($length);
        if ($boundedLength === 0) {
            $this->updateConsumedState();

            return '';
        }

        $data = \fread($resource, $boundedLength);
        if ($data === false) {
            throw new \RuntimeException('Unable to read from stream.');
        }

        if ($data !== '') {
            $this->started = true;
            $this->logicalPosition += \strlen($data);
        }

        $this->updateConsumedState();

        return $data;
    }

    /** @inheritDoc */
    public function getContents(): string
    {
        $resource = $this->ensureResource();

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable.');
        }

        $remaining = $this->remainingLogicalBytes();
        $contents = \stream_get_contents(
            $resource,
            $remaining ?? -1
        );
        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents.');
        }

        if ($contents !== '') {
            $this->started = true;
            $this->logicalPosition += \strlen($contents);
        }

        $this->updateConsumedState();

        return $contents;
    }

    /** @inheritDoc */
    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null && $this->resourceOpener === null) {
            return $key === null ? [] : null;
        }

        $resource = $this->ensureResource();
        $meta = \stream_get_meta_data($resource);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    /**
     * Access the resource for transport-native reads.
     *
     * @return resource
     *
     * @internal
     */
    public function getResource()
    {
        $resource = $this->ensureResource();

        return $resource;
    }

    /**
     * Record bytes read directly by the transport.
     *
     * @return void
     *
     * @internal
     */
    public function recordTransportRead(int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $this->started = true;
        $this->logicalPosition = \max(
            $this->logicalPosition,
            $this->startOffset + $bytes
        );
        $length = $this->length();
        if ($length !== null
            && $this->logicalPosition - $this->startOffset >= $length
        ) {
            $this->fullyConsumed = true;
        }
    }

    /** @inheritDoc */
    public function hash(string $algo = 'sha256', bool $raw = false): string
    {
        if ($this->resourceOpener !== null) {
            $resource = $this->openFreshResource();

            try {
                $ctx = \hash_init($algo);
                \hash_update_stream(
                    $ctx,
                    $resource,
                    $this->size ?? -1
                );

                return \hash_final($ctx, $raw);
            } finally {
                \fclose($resource);
            }
        }

        $resource = $this->ensureResource();

        if (!$this->isSeekable()) {
            throw new \RuntimeException(
                'Cannot hash a non-seekable resource stream.'
            );
        }

        $position = $this->tell();
        if (\fseek($resource, $this->startOffset) === -1) {
            throw new \RuntimeException(
                'Unable to seek resource stream for hashing.'
            );
        }

        try {
            $ctx = \hash_init($algo);
            \hash_update_stream(
                $ctx,
                $resource,
                $this->size ?? -1
            );

            return \hash_final($ctx, $raw);
        } finally {
            if (\fseek($resource, $position) === -1) {
                throw new \RuntimeException(
                    'Unable to restore resource stream position.'
                );
            }
        }
    }

    /** @inheritDoc */
    public function isReplayable(): bool
    {
        return $this->resourceOpener !== null
            || ($this->resource !== null && $this->isSeekable());
    }

    /** @inheritDoc */
    public function isAvailable(): bool
    {
        if ($this->resourceOpener !== null) {
            return true;
        }

        if ($this->resource === null) {
            return false;
        }

        $position = \ftell($this->resource);
        if ($position !== false && $position > $this->startOffset) {
            $this->started = true;
        }

        if (!$this->started) {
            return true;
        }

        $meta = \stream_get_meta_data($this->resource);

        return $meta['seekable'];
    }

    /** @inheritDoc */
    public function started(): bool
    {
        $this->synchronizeConsumedState();

        return $this->started;
    }

    /** @inheritDoc */
    public function fullyConsumed(): bool
    {
        $this->synchronizeConsumedState();

        return $this->fullyConsumed;
    }

    /**
     * Return the open resource, opening it first when it is deferred.
     *
     * @return resource
     * @throws \RuntimeException When the stream has been detached.
     */
    private function ensureResource()
    {
        if ($this->resource !== null) {
            return $this->resource;
        }

        if ($this->resourceOpener === null) {
            throw new \RuntimeException('Stream has been detached.');
        }

        $resource = $this->openFreshResource();
        $this->resource = $resource;
        $this->startOffset = self::resourcePosition($resource);
        $this->logicalPosition = $this->startOffset;

        return $resource;
    }

    /**
     * @return resource
     */
    private function openFreshResource()
    {
        if ($this->resourceOpener === null) {
            throw new \RuntimeException('Stream has no resource opener.');
        }

        $resource = ($this->resourceOpener)();
        if (!self::isStreamResource($resource)) {
            throw new \RuntimeException(
                'Resource body opener must return a valid PHP stream resource.'
            );
        }

        return $resource;
    }

    /**
     * @phpstan-assert-if-true resource $resource
     */
    private static function isStreamResource(mixed $resource): bool
    {
        return \is_resource($resource)
            && \get_resource_type($resource) === 'stream';
    }

    private function closeCurrentResource(): void
    {
        $resource = $this->resource;
        $this->resource = null;
        if (\is_resource($resource)) {
            \fclose($resource);
        }
    }

    /**
     * @param resource $resource
     */
    private static function resourcePosition($resource): int
    {
        $position = \ftell($resource);
        if ($position === false) {
            return 0;
        }

        return $position;
    }

    private function updateConsumedState(): void
    {
        $length = $this->length();
        if ($length === null) {
            $this->fullyConsumed = $this->started && $this->eof();

            return;
        }

        $consumed = $this->currentPosition() - $this->startOffset;
        $this->fullyConsumed = $consumed >= $length;
    }

    /**
     * @param int<0, max> $length Requested read length.
     *
     * @return int<0, max> Length capped at the remaining logical bytes.
     */
    private function limitReadLength(int $length): int
    {
        $remaining = $this->remainingLogicalBytes();

        return $remaining === null ? $length : \min($length, $remaining);
    }

    /**
     * @return int<0, max>|null Bytes left before the logical size, or null when unsized.
     */
    private function remainingLogicalBytes(): ?int
    {
        if ($this->size === null) {
            return null;
        }

        $consumed = \max(0, $this->currentPosition() - $this->startOffset);

        return \max(0, $this->size - $consumed);
    }

    private function currentPosition(): int
    {
        if ($this->resource === null) {
            return $this->logicalPosition;
        }

        $position = \ftell($this->resource);
        if ($position === false) {
            return $this->logicalPosition;
        }

        $this->logicalPosition = $position;

        return $position;
    }

    /**
     * Refresh state after the resource is read outside this object.
     *
     * @return void
     */
    private function synchronizeConsumedState(): void
    {
        if ($this->resource === null) {
            return;
        }

        $position = \ftell($this->resource);
        if ($position === false) {
            return;
        }

        if ($position > $this->startOffset) {
            $this->started = true;
        }

        $length = $this->length();
        if ($length === null) {
            if ($this->started && $this->eof()) {
                $this->fullyConsumed = true;
            }

            return;
        }

        if (($position - $this->startOffset) >= $length) {
            $this->fullyConsumed = true;
        }
    }
}
