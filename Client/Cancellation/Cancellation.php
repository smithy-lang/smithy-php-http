<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Cancellation;

/**
 * Create and trigger a cancellation signal.
 */
final class Cancellation implements CancellationInterface
{
    /** @var array<int, \Closure(CancelledException): void> */
    private array $subscribers = [];
    private int $nextSubscriptionId = 0;
    private ?CancelledException $reason = null;

    /**
     * Determine whether cancellation was requested.
     */
    public function isCancelled(): bool
    {
        return $this->reason !== null;
    }

    /**
     * Get the cancellation reason.
     */
    public function reason(): ?CancelledException
    {
        return $this->reason;
    }

    /**
     * Throw when cancellation was requested.
     *
     * @return void
     * @throws CancelledException If cancellation was requested.
     */
    public function throwIfCancelled(): void
    {
        if ($this->reason !== null) {
            throw $this->reason;
        }
    }

    /**
     * Subscribe to cancellation.
     *
     * @param \Closure(CancelledException): void $callback
     *
     * @return int Subscription identifier.
     */
    public function subscribe(\Closure $callback): int
    {
        if ($this->reason !== null) {
            $callback($this->reason);

            return 0;
        }

        $subscriptionId = ++$this->nextSubscriptionId;
        $this->subscribers[$subscriptionId] = $callback;

        return $subscriptionId;
    }

    /**
     * Remove a cancellation subscription.
     *
     * @return void
     */
    public function unsubscribe(int $subscriptionId): void
    {
        unset($this->subscribers[$subscriptionId]);
    }

    /**
     * Request cancellation.
     *
     * @return void
     */
    public function cancel(?\Throwable $reason = null): void
    {
        if ($this->reason !== null) {
            return;
        }

        $cancelled = new CancelledException(
            'Operation was cancelled.',
            0,
            $reason
        );
        $this->reason = $cancelled;
        $subscribers = $this->subscribers;
        $this->subscribers = [];

        $callbackError = null;

        foreach ($subscribers as $subscriber) {
            try {
                $subscriber($cancelled);
            } catch (\Throwable $error) {
                $callbackError ??= $error;
            }
        }

        if ($callbackError !== null) {
            throw $callbackError;
        }
    }
}
