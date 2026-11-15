<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Psr\Http\Message\RequestInterface;
use Smithy\Http\Client\Cancellation\CancellationInterface;
use Smithy\Http\Client\Cancellation\CancelledException;
use Smithy\Http\Client\{
    HttpRequestOptions,
    UploadState
};

/**
 * Track a pending asynchronous HTTP request.
 *
 * @internal
 */
final class PendingRequest
{
    public ?int $cancellationSubscription = null;
    public ?CancelledException $cancellationReason = null;
    public int $downloadedBytes = 0;
    public int $uploadedBytes = 0;
    public readonly float $startedAt;
    private ?\Closure $resume;
    private ?\Closure $throw;

    public function __construct(
        public readonly CurlConnection $exchange,
        public readonly RequestInterface $request,
        \Closure $resume,
        \Closure $throw,
        public readonly HttpRequestOptions $options,
        public readonly CurlResponseState $responseState,
        public readonly ?int $requestBodySize,
        public readonly ?UploadState $uploadState = null,
        public readonly ?CancellationInterface $cancellation = null
    )
    {
        $this->startedAt = \microtime(true);
        $this->resume = $resume;
        $this->throw = $throw;
    }

    /**
     * Resume the suspended request and release its continuations.
     *
     * @return void
     */
    public function resumeWith(mixed $value): void
    {
        $resume = $this->resume;
        $this->resume = null;
        $this->throw = null;
        $resume?->__invoke($value);
    }

    /**
     * Fail the suspended request and release its continuations.
     *
     * @return void
     */
    public function resumeThrowing(\Throwable $error): void
    {
        $throw = $this->throw;
        $this->resume = null;
        $this->throw = null;
        $throw?->__invoke($error);
    }

    /**
     * Get the raw cURL handle.
     */
    public function getHandle(): \CurlHandle
    {
        return $this->exchange->getHandle();
    }
}
