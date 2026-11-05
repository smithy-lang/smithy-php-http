<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Extend PSR-7 streams with request body retry and signing helpers.
 */
interface RequestStreamInterface extends StreamInterface
{
    /**
     * Get the request body length when it is known.
     */
    public function length(): ?int;

    /**
     * Compute a hash of the request body contents.
     */
    public function hash(string $algo = 'sha256', bool $raw = false): string;

    /**
     * Determine whether the request body can be replayed for retry.
     */
    public function isReplayable(): bool;

    /**
     * Determine whether the request body can still be read or replayed.
     */
    public function isAvailable(): bool;

    /**
     * Rewind the request body to the retry start position.
     *
     * @return void
     */
    public function rewindForRetry(): void;

    /**
     * Determine whether the request body has started streaming.
     */
    public function started(): bool;

    /**
     * Determine whether the request body has been fully consumed.
     */
    public function fullyConsumed(): bool;
}
