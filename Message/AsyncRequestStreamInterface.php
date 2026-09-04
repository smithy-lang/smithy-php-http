<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Expose readiness for incrementally produced request bodies.
 */
interface AsyncRequestStreamInterface extends RequestStreamInterface
{
    /**
     * Determine whether a read can return data, EOF, or an error.
     */
    public function isReady(): bool;

    /**
     * Suspend until a read can return data, EOF, or an error.
     *
     * @return void
     */
    public function awaitReadable(): void;

    /**
     * Subscribe to transitions from unavailable to readable.
     *
     * The callback receives no arguments. It is a readiness signal only:
     * after it runs, a read can return data, EOF, or an error.
     *
     * @param \Closure(): void $callback
     *
     * @return int Subscription identifier.
     */
    public function subscribe(\Closure $callback): int;

    /**
     * Remove a readiness subscription.
     *
     * @return void
     */
    public function unsubscribe(int $subscriptionId): void;

    /**
     * Signal that the active consumer will no longer drain the body.
     *
     * @return void
     */
    public function detachConsumer(): void;
}
