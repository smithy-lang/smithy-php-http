<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client;

use Smithy\Http\Message\RequestStreamInterface;

/**
 * Track mutable request upload state shared with transport callbacks.
 *
 * @internal
 */
final class UploadState
{
    /** Error thrown by the request body read callback. */
    public ?\Throwable $readError = null;
    /** Number of request body bytes returned to the transport. */
    public int $bytesRead = 0;
    /** Final bytes withheld until an async body reaches a terminal state. */
    public ?string $retainedFinalChunk = null;
    /** Readiness subscription for an asynchronous body. */
    public ?int $readinessSubscription = null;
    /** Whether libcurl paused the upload for lack of source data. */
    public bool $paused = false;
    /** Whether an upload resume callback is already scheduled. */
    public bool $resumeScheduled = false;

    /**
     * Create upload state for a streamed request body.
     */
    public function __construct(
        public readonly RequestStreamInterface $body,
        public readonly ?int $expectedLength = null
    ) {}
}
