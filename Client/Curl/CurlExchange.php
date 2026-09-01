<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Curl;

use Smithy\Http\Client\Connection\ExchangeInterface;
use Smithy\Http\Client\Connection\HttpConnectionInterface;

/**
 * HTTP exchange backed by a cURL connection.
 *
 * @internal
 */
final class CurlExchange implements ExchangeInterface
{
    public function __construct(
        private readonly CurlConnection $connection
    ) {}

    /** @inheritDoc */
    public function getConnection(): HttpConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Access the raw cURL handle for transport operations.
     */
    public function getHandle(): \CurlHandle
    {
        return $this->connection->getHandle();
    }

    /**
     * Access the typed cURL connection.
     */
    public function getCurlConnection(): CurlConnection
    {
        return $this->connection;
    }
}
