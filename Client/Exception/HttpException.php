<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown on transport-level HTTP failures.
 *
 * This covers connection refused, timeouts, DNS resolution failures,
 * and similar transport errors.
 */
class HttpException extends \RuntimeException implements
    ClientExceptionInterface
{
    public readonly RequestInterface $request;

    public function __construct(
        string $message,
        RequestInterface $request,
        ?\Throwable $previous = null,
        public readonly bool $requestBodyStarted = false,
        public readonly bool $requestBodyFullyConsumed = false,
        public readonly ?bool $requestBodySeekable = null
    )
    {
        parent::__construct($message, 0, $previous);
        $this->request = ExceptionRequestSanitizer::sanitize($request);
    }

    /**
     * Return the request associated with the failure.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
