<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Expose transport-controlled response body streaming.
 */
interface ResponseStreamInterface extends StreamInterface
{
    /**
     * Discard unread bytes while allowing the transport to finish cleanly.
     *
     * @return void
     */
    public function discard(): void;

    /**
     * Determine whether response bytes have been consumed.
     */
    public function started(): bool;

    /**
     * Determine whether the response stream reached EOF without failure.
     */
    public function fullyConsumed(): bool;

    /**
     * Get the number of bytes currently buffered for reads.
     */
    public function bufferedBytes(): int;

    /**
     * Determine whether the transport can append a chunk of the given size.
     */
    public function canAppend(int $length): bool;

    /**
     * Determine whether the transport can resume after a blocked append.
     */
    public function canResume(int $length): bool;

    /**
     * Append response bytes from the transport.
     */
    public function append(string $chunk): bool;

    /**
     * Mark the response body complete.
     *
     * @return void
     */
    public function end(): void;

    /**
     * Fail the response body.
     *
     * @return void
     */
    public function fail(\Throwable $failure): void;
}
