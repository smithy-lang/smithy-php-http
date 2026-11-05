<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Client;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Smithy\Client\Transport\{
    MessageExchangeInterface,
    TransportContext,
    TransportInterface,
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Http\Client\Exception\HttpException;
use Smithy\Http\Message\{
    HttpMessageExchange,
    HttpTransportRequest,
    HttpTransportResponse,
    RequestStreamInterface
};

/**
 * Adapts an HTTP client to the generic client transport contract.
 */
final readonly class HttpClientTransport implements TransportInterface
{
    public function __construct(
        public HttpClientInterface $httpClient
    ) {}

    /** @inheritDoc */
    public function getMessageExchange(): MessageExchangeInterface
    {
        return HttpMessageExchange::instance();
    }

    /** @inheritDoc */
    public function getDefaultOptions(): HttpRequestOptions
    {
        return $this->httpClient->getDefaultRequestOptions();
    }

    /** @inheritDoc */
    public function send(
        TransportRequestInterface $request,
        TransportContext $context
    ): TransportResponseInterface
    {
        $options = $context->options;
        if ($options !== null && !$options instanceof HttpRequestOptions) {
            throw new \InvalidArgumentException(
                'HTTP transport options must be an instance of '
                    . HttpRequestOptions::class . '.'
            );
        }

        $httpRequest = HttpTransportRequest::expect($request);

        return new HttpTransportResponse(
            $this->httpClient->sendRequest($httpRequest->request, $options)
        );
    }

    /** @inheritDoc */
    public function prepareRequestForRetry(
        TransportRequestInterface $request,
        ?TransportResponseInterface $response,
        TransportContext $context,
        \Throwable $error
    ): bool
    {
        $httpRequest = HttpTransportRequest::expect($request)->request;
        $httpResponse = $response === null
            ? null
            : HttpTransportResponse::expect($response)->response;
        $options = $context->options ?? $this->getDefaultOptions();
        if (!$options instanceof HttpRequestOptions) {
            return false;
        }

        $body = $httpRequest->getBody();
        if ($body->getSize() !== 0
            && !self::prepareRequestBodyForRetry(
                $body,
                $httpResponse,
                $error
            )
        ) {
            return false;
        }

        try {
            $options->trailers?->rewindForRetry();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private static function prepareRequestBodyForRetry(
        StreamInterface $body,
        ?ResponseInterface $response,
        \Throwable $error
    ): bool
    {
        try {
            if ($body instanceof RequestStreamInterface
                && $body->isReplayable()
            ) {
                $body->rewindForRetry();

                return true;
            }
            if ($body->isSeekable()) {
                $body->rewind();

                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        if ($response !== null) {
            return false;
        }

        $httpError = self::findHttpException($error);
        if ($httpError !== null) {
            return $httpError->requestBodySeekable === false
                && !$httpError->requestBodyStarted
                && !$httpError->requestBodyFullyConsumed;
        }

        try {
            return $body instanceof RequestStreamInterface
                && !$body->started()
                && !$body->fullyConsumed();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function findHttpException(
        \Throwable $error
    ): ?HttpException
    {
        do {
            if ($error instanceof HttpException) {
                return $error;
            }

            $error = $error->getPrevious();
        } while ($error !== null);

        return null;
    }
}
