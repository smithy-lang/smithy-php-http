<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client;

use Smithy\Async\Async;
use Smithy\Async\EventLoopInterface;

/**
 * Provide shared behavior for HTTP client implementations.
 *
 * Implements the HttpClient interface and provides shared
 * infrastructure: event loop and default request options.
 * Revolt is a required dependency. The event loop is always
 * available.
 *
 * Concrete subclasses add transport-specific logic.
 */
abstract class AbstractHttpClient implements HttpClientInterface
{
    protected readonly EventLoopInterface $eventLoop;
    protected readonly HttpRequestOptions $defaultOptions;

    public function __construct(
        ?EventLoopInterface $eventLoop = null,
        ?HttpRequestOptions $defaultOptions = null
    )
    {
        $this->eventLoop = $eventLoop ?? Async::eventLoop();
        $this->defaultOptions = $defaultOptions ?? new HttpRequestOptions();
    }

    /**
     * Access the event loop.
     */
    public function getEventLoop(): EventLoopInterface
    {
        return $this->eventLoop;
    }

    /**
     * Return default request options.
     */
    public function getDefaultRequestOptions(): HttpRequestOptions
    {
        return $this->defaultOptions;
    }
}
