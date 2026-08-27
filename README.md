# Smithy PHP HTTP

PSR-7 messages, PSR-18 client contracts, and connection primitives for Smithy
PHP. Requires PHP 8.2 or later.

## Installation

```shell
composer require smithy/http
```

## Sending Requests

```php
use Smithy\Http\Client\HttpClientInterface;
use Smithy\Http\Message\Request;

function fetchStatus(HttpClientInterface $client): int
{
    $response = $client->sendRequest(
        new Request('GET', 'https://example.com/status')
    );

    return $response->getStatusCode();
}
```

## Request Options

`HttpRequestOptions` configures one transfer:

- total and read timeouts
- redirect and proxy behavior
- response buffering limits
- content decoding and body-size limits
- request trailers and upload buffers

## Messages and Streams

The package provides PSR-7 request, response, URI, and stream
implementations. `BodyStreamInterface` adds length, replay, availability, and
consumption state used by retries and signing.

## Connections

Connection interfaces remain transport-neutral and accept caller-supplied
implementations.

## Dependencies

This package depends on `psr/http-client` and `psr/http-message`.

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Http
```
