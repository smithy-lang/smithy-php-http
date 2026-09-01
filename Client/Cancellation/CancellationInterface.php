<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Cancellation;

/**
 * Observe cancellation of an asynchronous operation.
 */
interface CancellationInterface
{
    /**
     * Determine whether cancellation was requested.
     */
    public function isCancelled(): bool;

    /**
     * Get the cancellation reason.
     */
    public function reason(): ?CancelledException;

    /**
     * Throw when cancellation was requested.
     *
     * @return void
     * @throws CancelledException If cancellation was requested.
     */
    public function throwIfCancelled(): void;

    /**
     * Subscribe to cancellation.
     *
     * @param \Closure(CancelledException): void $callback
     *
     * @return int Subscription identifier.
     */
    public function subscribe(\Closure $callback): int;

    /**
     * Remove a cancellation subscription.
     *
     * @return void
     */
    public function unsubscribe(int $subscriptionId): void;
}
