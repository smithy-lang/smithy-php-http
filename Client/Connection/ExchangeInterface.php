<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Connection;

/**
 * A protocol-agnostic handle for executing a single HTTP request.
 *
 * For HTTP/1.1, an Exchange wraps an entire connection (one request
 * per connection at a time). For HTTP/2, an Exchange wraps a single
 * stream on a multiplexed connection.
 *
 * The Exchange is the unit of work that the ConnectionManager leases
 * to the HttpClient and that the HttpClient returns when done.
 */
interface ExchangeInterface
{
    /**
     * Return the underlying connection this exchange uses.
     */
    public function getConnection(): HttpConnectionInterface;
}
