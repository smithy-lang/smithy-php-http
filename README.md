# Smithy PHP HTTP

PSR-7 messages and a fiber-aware cURL transport for Smithy PHP. Requires PHP
8.2 or later.

## Installation

```shell
composer require smithy/http
```

`CurlClient` requires the cURL extension. The transport uses
`revolt/event-loop` for cooperative I/O.

## Sending Requests

```php
use Smithy\Http\Curl\CurlClient;
use Smithy\Http\Message\Request;

$client = new CurlClient();
$response = $client->send(
    new Request('GET', 'https://example.com/status')
);

echo $response->getStatusCode();
```

Calls outside a fiber block until completion. Calls inside fibers suspend
while cURL waits for network I/O.

`sendMulti()` runs requests concurrently through one shared cURL multi handle
and returns responses in input order.

## Request Options

`HttpRequestOptions` configures one transfer:

- total and read timeouts
- redirect and proxy behavior
- buffered or streamed response delivery
- content decoding and body-size limits
- request trailers and upload buffers
- cancellation

`HttpClientConfiguration` groups connection-pool settings, connection
settings, and default request options for generated clients.

## Cancellation

```php
use Smithy\Http\Cancellation\Cancellation;
use Smithy\Http\Client\HttpRequestOptions;

$cancellation = new Cancellation();
$options = new HttpRequestOptions(cancellation: $cancellation);

$cancellation->cancel();
$client->send($request, $options);
```

Cancellation interrupts connection acquisition or an active transfer.
`CurlClient` surfaces the reason as `RequestCancelledException`.

## Messages and Streams

The package provides PSR-7 request, response, URI, and stream
implementations. `BodyStreamInterface` adds length, replay, availability, and
consumption state used by retries and signing.

`ResponseBodyStream` supports bounded streamed downloads with transport
backpressure. `AsyncBodyStream` and `ChunkedBodyStream` support incremental
request bodies.

## Connections

`CurlConnectionManager` enforces per-route concurrency, reuses idle easy
handles, and exposes pool configuration. Connection interfaces remain
transport-neutral and accept caller-supplied implementations.

## Dependencies

This package depends on `smithy/async`, `psr/http-message`, and
`revolt/event-loop`.

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Http
```
