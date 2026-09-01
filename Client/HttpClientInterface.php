<?php

declare(strict_types=1);

namespace Smithy\Http\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Smithy\Http\Client\Connection\ConnectionManagerInterface;
use Smithy\Http\Client\Exception\HttpException;

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
     * @param RequestInterface $request PSR-7 request to send.
     * @param HttpRequestOptions|null $options Per-request transport options.
     *
     * @return ResponseInterface HTTP response.
     *
     * @throws HttpException On transport-level failures such as connection
     *                       refusal, timeout, or DNS resolution failure.
     * @throws NetworkExceptionInterface On network send failure.
     * @throws RequestExceptionInterface If the request is invalid.
     */
    public function sendRequest(
        RequestInterface $request,
        ?HttpRequestOptions $options = null
    ): ResponseInterface;

    /**
     * Access the connection manager used by this client.
     *
     * Exposes connection pool metrics and allows external pruning
     * in long-running processes.
     */
    public function getConnectionManager(): ConnectionManagerInterface;

    /**
     * Release resources held by the client.
     *
     * Implementations should reject new requests after close. Active
     * requests may be failed during shutdown.
     *
     * @return void
     */
    public function close(): void;
}
