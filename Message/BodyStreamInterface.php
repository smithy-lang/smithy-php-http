<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Extend PSR-7 streams with request/response body helpers.
 */
interface BodyStreamInterface extends StreamInterface
{
    /**
     * Get the body length when it is known.
     */
    public function length(): ?int;

    /**
     * Compute a hash of the body contents.
     */
    public function hash(string $algo = 'sha256', bool $raw = false): string;

    /**
     * Determine whether the body can be replayed for retry.
     */
    public function isReplayable(): bool;

    /**
     * Determine whether the body can still be read or replayed.
     */
    public function isAvailable(): bool;

    /**
     * Rewind the body to the retry start position.
     *
     * @return void
     */
    public function rewindForRetry(): void;

    /**
     * Determine whether the body has started streaming.
     */
    public function started(): bool;

    /**
     * Determine whether the body has been fully consumed.
     */
    public function fullyConsumed(): bool;
}
