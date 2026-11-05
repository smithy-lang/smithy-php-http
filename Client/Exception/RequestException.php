<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown for invalid requests.
 */
class RequestException extends \InvalidArgumentException implements
    RequestExceptionInterface
{
    public readonly RequestInterface $request;

    public function __construct(
        string $message,
        RequestInterface $request,
        ?\Throwable $previous = null
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
