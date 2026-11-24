# Smithy PHP HTTP Client

PSR-18 cURL client with fiber-aware concurrency, HTTP/1.1 and HTTP/2
support, bounded body streaming, cancellation, and per-route connection
limits. Requires PHP 8.2 or later.

## Installation

```shell
composer require smithy/http
```

`CurlClient` requires the cURL extension. The transport uses
`revolt/event-loop` for cooperative I/O and the
[Smithy HTTP message types](../Message/README.md) at its PSR-7 boundary.

## Capabilities

| Area | Behavior |
|---|---|
| HTTP versions | HTTP/1.1, HTTP/2, or ALPN negotiation |
| Concurrency | Fiber suspension over one shared cURL multi handle |
| Request bodies | Known-length, unknown-length, replayable, and incremental bodies |
| Response bodies | Automatic buffering or bounded streaming with backpressure |
| Cancellation | Connection acquisition, active transfers, and streamed response bodies |
| Connections | Per-route concurrency, FIFO waiters, acquisition timeouts, and idle handle reuse |
| TLS | Peer verification, custom trust stores, client certificates, and cipher allowlists |
| Response trailers | HTTP/1.1 and HTTP/2 trailers after body completion |
| Errors | PSR-18 request and network exceptions with request body state |

## Sending Requests

`CurlClient` implements `Psr\Http\Client\ClientInterface`. It accepts any
PSR-7 request and returns a PSR-7 response.

```php
use Smithy\Http\Client\CurlClient;
use Smithy\Http\Message\Request;

$client = new CurlClient();
$response = $client->sendRequest(
    new Request('GET', 'https://example.com/status')
);

echo $response->getStatusCode();
```

Calls outside a fiber block until completion. Calls inside fibers suspend
while cURL waits for network I/O.

## Concurrent Requests

`CurlClient` shares its cURL multi handle across requests. Use
`Smithy\Async\Async` to start concurrent transfers.

```php
use Smithy\Async\Async;
use Smithy\Http\Message\Request;

$responses = Async::map(
    $urls,
    fn(string $url) => $client->sendRequest(
        new Request('GET', $url)
    ),
    concurrency: 20
);
```

`Async::map()` preserves input order and limits the number of active
operations. `Async::all()` runs a fixed set of operations concurrently.

## Request Options

Pass defaults to the `CurlClient` constructor or provide options for one
request through `sendRequest()`.

```php
use Smithy\Http\Client\CurlClient;
use Smithy\Http\Client\HttpRequestOptions;

$client = new CurlClient(
    defaultOptions: new HttpRequestOptions(
        timeout: 15.0,
        readTimeout: 10.0,
        maxResponseBodyBytes: 8 * 1024 * 1024
    )
);

$response = $client->sendRequest(
    $request,
    new HttpRequestOptions(timeout: 5.0)
);
```

| Option | Default | Behavior |
|---|---:|---|
| `timeout` | 30 seconds | Limits time to final headers for streamed responses and full delivery for buffered responses. Zero disables the limit. |
| `readTimeout` | 30 seconds | Limits inactivity while reading a streamed body. Zero disables the limit. |
| `proxy` | `null` | Routes the request through the supplied proxy URL. |
| `decodeContent` | `true` | Requests automatic content decoding from cURL. |
| `cancellation` | `null` | Cancels connection acquisition or transfer work. |
| `maxBufferedResponseBytes` | 1 MiB | Streams responses above this decoded size. |
| `responseBufferSize` | 64 KiB | Bounds queued bytes for streamed responses. |
| `maxResponseBodyBytes` | `0` | Limits decoded response bytes. Zero disables the limit. |
| `uploadBufferSize` | 128 KiB | Sets the preferred cURL upload buffer size. |
| `bufferedDownloadBufferSize` | 256 KiB | Sets the preferred buffer for automatic downloads. |
| `trailers` | `null` | Supplies request trailers to transports that support them. |

`CurlClient` requires `trailers` to remain `null` and throws
`InvalidArgumentException` when a request supplies them.
Redirect responses are returned to the caller and are not followed.

## Request Transfer

The client derives upload behavior from the request headers and body
metadata. It supports known and unknown lengths, HTTP/1.1 chunked transfer,
HTTP/2 streaming, and incremental producers.

The transport validates declared body lengths. It reports premature EOF,
extra bytes, and stalled producers as request failures. It never follows
redirect responses, so request bodies are not replayed automatically.

The [message README](../Message/README.md) describes the available request
body implementations.

## Response Delivery

`CurlClient` chooses buffered or streamed delivery after receiving the final
response headers.

- A response with a known decoded length at or below
  `maxBufferedResponseBytes` is buffered before delivery.
- A response with an unknown decoded length is streamed.
- A response above `maxBufferedResponseBytes` is streamed.

Streamed responses return after the final headers arrive. The body may remain
active after `sendRequest()` returns. The bounded response queue pauses cURL
when the application falls behind and resumes the transfer as bytes are
consumed.

Call `discardBody()` to drain the remaining bytes. Draining the active
transfer permits connection reuse.

Response trailers become available after the body completes or is discarded.
The client supports trailers delivered through HTTP/1.1 and HTTP/2.

## Cancellation

`Cancellation` is a one-shot signal. It can stop a request before connection
acquisition, while waiting for capacity, during transfer, or while consuming
a streamed response body.

```php
use Smithy\Http\Client\Cancellation\Cancellation;
use Smithy\Http\Client\HttpRequestOptions;

$cancellation = new Cancellation();
$options = new HttpRequestOptions(cancellation: $cancellation);

$cancellation->cancel();
$client->sendRequest($request, $options);
```

`CurlClient` reports cancellation as `RequestCancelledException`. Pass a
throwable to `cancel()` to retain the application failure as the cancellation
cause.

## Connection Management

`CurlConnectionManager` leases cURL easy handles by scheme, host, port, and
proxy. It uses FIFO waiters when a route reaches its concurrency limit.
libcurl owns the underlying reusable socket cache.

Connection manager defaults are:

| Setting | Default |
|---|---:|
| Maximum concurrent requests per route | 100 |
| Maximum idle handles per route | 100 |
| Maximum idle handles across routes | 100 |
| Idle timeout | 60 seconds |
| Connection acquisition timeout | 10 seconds |

`ConnectionManagerInterface` allows another connection manager to be passed
to `CurlClient`. It also exposes `prune()`, `clear()`, `idleCount()`, and its
configuration for long-running processes.

Call `HttpClientInterface::close()` when a client is no longer needed. For
`CurlClient`, this fails active transfers, clears pooled easy handles, cancels
transport timers, and closes the shared cURL multi handle. New requests are
rejected after close.

## HTTP and TLS Configuration

`ConnectConfiguration` controls connection establishment:

- connect timeout
- automatic, HTTP/1.1, or HTTP/2 version policy
- HTTP/2 concurrent stream limit
- TLS peer verification and trust stores
- client certificate and private key
- TLS 1.2 and TLS 1.3 cipher allowlists

TLS 1.2 is the minimum supported version. Peer verification is enabled by
default.

```php
use Smithy\Http\Client\Configuration\ConnectConfiguration;
use Smithy\Http\Client\Configuration\HTTP2Configuration;
use Smithy\Http\Client\Configuration\HTTPVersionPolicy;
use Smithy\Http\Client\CurlClient;

$client = new CurlClient(
    connectConfiguration: new ConnectConfiguration(
        connectTimeout: 5.0,
        versionPolicy: HTTPVersionPolicy::EnforceHTTP_2,
        h2Configuration: new HTTP2Configuration(maxStreams: 50)
    )
);
```

Automatic mode negotiates HTTP/2 over TLS through ALPN and falls back to
HTTP/1.1. Plain HTTP uses HTTP/1.1 unless HTTP/2 is enforced. Enforced version
policies reject a response negotiated with another version.

## Errors

HTTP status codes are returned as responses. The client uses these exception
types for request and transport failures:

| Exception | Condition |
|---|---|
| `NetworkException` | Connection, DNS, capacity acquisition, timeout, or transfer failure |
| `RequestException` | Invalid request metadata or body behavior |
| `RequestCancelledException` | Cancellation signal triggered |

`NetworkException` and `RequestCancelledException` extend `HttpException`.
`HttpException` records whether the request body started, whether it was fully
consumed, and whether it was seekable. Retry policies can use this state to
decide whether sending the request again is safe.

## Dependencies

The client depends on `smithy/async`, `psr/http-client`,
`psr/http-message`, and `revolt/event-loop`.

## Design

See [Curl HTTP Transport Design](https://github.com/smithy-lang/smithy-php/blob/main/docs/design/http/client/curl-http-transport.md).

## Contributing

Run the client tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Http/Client
```
