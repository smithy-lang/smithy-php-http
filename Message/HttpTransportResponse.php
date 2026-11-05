<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\ResponseInterface;
use Smithy\Client\Transport\TransportResponseInterface;

/**
 * Transport-neutral envelope containing one PSR-7 HTTP response.
 */
final readonly class HttpTransportResponse implements
    TransportResponseInterface
{
    public function __construct(
        public ResponseInterface $response
    ) {}

    /**
     * Require an HTTP response envelope.
     */
    public static function expect(
        TransportResponseInterface $response
    ): self
    {
        if ($response instanceof self) {
            return $response;
        }

        throw new \InvalidArgumentException(
            'Expected transport response of type ' . self::class
                . ', got ' . $response::class . '.'
        );
    }

    /**
     * Return an envelope containing a replacement PSR-7 response.
     */
    public function withResponse(ResponseInterface $response): self
    {
        return $response === $this->response ? $this : new self($response);
    }
}
