<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Checksum;

use Psr\Http\Message\StreamInterface;
use Smithy\Core\Checksum\Checksum;

/**
 * Wrap a response body and validate its checksum as the payload is read.
 *
 * The expected checksum is computed incrementally from the bytes the caller
 * reads and compared once the body reaches EOF. A mismatch raises a
 * {@see ChecksumMismatchException}; a match leaves {@see isValidated()} true.
 */
final class ChecksumStream implements StreamInterface
{
    private Checksum $checksum;

    private readonly int $startPosition;

    private bool $validationComplete = false;

    private bool $validated = false;

    private ?ChecksumMismatchException $failure = null;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly ExpectedChecksum $expected,
    ) {
        $this->startPosition = $inner->isSeekable() ? $inner->tell() : 0;
        $this->checksum = $expected->algorithm->create();
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->validationComplete) {
            $this->inner->seek($offset, $whence);

            return;
        }

        if ($whence === SEEK_CUR && $offset === 0) {
            return;
        }
        if ($whence === SEEK_SET && $offset === $this->startPosition) {
            $this->inner->seek($offset);
            $this->resetValidation();

            return;
        }

        throw new \RuntimeException(
            'Cannot seek a response body before checksum validation completes.'
        );
    }

    public function rewind(): void
    {
        if (!$this->inner->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable.');
        }

        $this->inner->seek($this->startPosition);
        $this->resetValidation();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException(
            'Checksum-validating response bodies are not writable.'
        );
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        $this->throwIfFailed();

        $chunk = $this->inner->read($length);
        $this->update($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $this->throwIfFailed();

        $contents = $this->inner->getContents();
        $this->update($contents);

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    public function isValidationComplete(): bool
    {
        return $this->validationComplete;
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }

    public function getAlgorithm(): string
    {
        return $this->expected->algorithm->value;
    }

    private function update(string $bytes): void
    {
        if ($this->validationComplete) {
            return;
        }

        if ($bytes !== '') {
            $this->checksum->update($bytes);
        }

        if ($this->inner->eof()) {
            $this->completeValidation();
        }
    }

    private function completeValidation(): void
    {
        if ($this->validationComplete) {
            return;
        }

        $calculated = $this->checksum->digest();
        $this->validationComplete = true;
        if ($this->expected->matches($calculated)) {
            $this->validated = true;

            return;
        }

        $this->failure = new ChecksumMismatchException(
            algorithm: $this->expected->algorithm,
            expectedChecksum: $this->expected->base64Digest,
            calculatedChecksum: \base64_encode($calculated),
        );
        throw $this->failure;
    }

    private function resetValidation(): void
    {
        $this->checksum = $this->expected->algorithm->create();
        $this->validationComplete = false;
        $this->validated = false;
        $this->failure = null;
    }

    private function throwIfFailed(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
