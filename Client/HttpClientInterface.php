<?php

declare(strict_types=1);

namespace Smithy\Http\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Define transport-agnostic HTTP client operations.
 *
 * Uses PSR-7 requests and responses at the transport boundary.
 */
interface HttpClientInterface extends ClientInterface
{
    /**
     * Send a single HTTP request and return the response.
     *
     * @param RequestInterface $request  PSR-7 request to send.
     * @param HttpRequestOptions|null $options Per-request transport options.
     *
     * @return ResponseInterface HTTP response.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface On transport-level
     *                                                   failures.
     */
    public function sendRequest(
        RequestInterface $request,
        ?HttpRequestOptions $options = null
    ): ResponseInterface;
}
