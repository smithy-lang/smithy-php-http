# Smithy PHP HTTP Messages

PSR-7 request, response, URI, and stream implementations for Smithy PHP.
Includes body metadata used by signing, retries, and HTTP transports.
Requires PHP 8.2 or later.

## Installation

```shell
composer require smithy/http
```

## Message Types

| Type | Responsibility |
|---|---|
| `Request` | Immutable PSR-7 request |
| `Response` | Immutable PSR-7 response with body discard and trailer access |
| `Uri` | Immutable PSR-7 URI |
| `Stream` | PSR-7 stream backed by a PHP resource |
| `RequestStreamInterface` | Request length, hashing, replay, and consumption state |
| `ResponseStreamInterface` | Transport-controlled response streaming and discard |
| `ResponseTrailers` | Trailer fields and their transport lifecycle |

Message constructors validate methods, status codes, protocol versions,
headers, reason phrases, and URIs. PSR-7 `withXxx()` methods return a new
message instance.

## Creating Messages

`Request` and `Response` accept a string, a PSR-7 stream, or `null` for the
body.

```php
use Smithy\Http\Message\Request;
use Smithy\Http\Message\Response;

$request = new Request(
    method: 'POST',
    uri: 'https://example.com/items',
    headers: ['Content-Type' => 'application/json'],
    body: '{"name":"example"}'
);

$response = new Response(
    status: 201,
    headers: ['Content-Type' => 'application/json'],
    body: '{"id":"item-1"}'
);
```

When the `Host` header is omitted, `Request` derives it from the URI.
`Response` supplies the standard reason phrase when the caller omits one.

`Request::fromNormalized()` and `Response::fromNormalized()` accept trusted
headers in the internal normalized representation. Protocol serializers use
these factories to skip repeated header normalization after validating
application-controlled header names and values.

## Streams

`RequestStreamInterface` extends `Psr\Http\Message\StreamInterface` with state
needed by transports and retry policies:

- known request length
- content hashing
- replay support and retry rewind
- request availability
- transfer start and completion state

The package provides these stream implementations:

| Type | Use |
|---|---|
| `Stream` | General PHP resource or temporary PSR-7 stream |
| `StringRequestStream` | Immutable in-memory request body |
| `ResourceRequestStream` | File or resource request body with optional replay |
| `StreamBodyAdapter` | Request metadata around another PSR-7 stream |
| `AsyncRequestStream` | Incrementally produced request body |
| `StreamingResponseStream` | Transport-fed streaming response body |

### Resource Bodies

`ResourceRequestStream::fromFile()` opens a file when consumption starts and
reopens it when a retry rewinds the body.

```php
use Smithy\Http\Message\Request;
use Smithy\Http\Message\ResourceRequestStream;

$request = new Request(
    method: 'PUT',
    uri: 'https://example.com/archive',
    headers: ['Content-Type' => 'application/octet-stream'],
    body: ResourceRequestStream::fromFile('/path/to/archive.bin')
);
```

`ResourceRequestStream::create()` accepts a resource opener and an optional
length. The opener must return a fresh resource positioned at the logical
body start. `ResourceRequestStream::temp()` creates a seekable temporary body.

### Incremental Request Bodies

`AsyncRequestStream` allows a producer to write chunks while a transport
consumes earlier chunks. Its bounded queue suspends the producer when
buffered bytes reach the configured high-water mark.

```php
use Smithy\Async\RevoltEventLoop;
use Smithy\Http\Message\AsyncRequestStream;

$body = new AsyncRequestStream(
    new RevoltEventLoop(),
    expectedLength: $contentLength,
    highWaterMark: 64 * 1024
);

$body->write($chunk);
$body->end();
```

Call `end()` after the final chunk. Call `fail()` to terminate reads with the
producer error. `AsyncRequestStream` supports one forward-only transfer.

### Streamed Response Bodies

`ResponseStreamInterface` extends `Psr\Http\Message\StreamInterface` with
transport-facing methods for appending bytes, ending the stream, failing the
stream, and discarding unread bytes.

`StreamingResponseStream` buffers a bounded number of bytes between the
transport and the consumer. Reads suspend until bytes arrive or the stream
reaches a terminal state. Consuming bytes allows a paused transport to resume.

Call `discard()` to drain the active transfer. Closing the stream aborts the
body.

## Response Trailers

`ResponseInterface` extends the PSR-7 response contract with
`discardBody()` and `getTrailers()`.

```php
$response->getBody()->getContents();

$checksum = $response->getTrailers()
    ->getHeaderLine('x-checksum');
```

Trailer fields remain pending until the response body completes or is
discarded. Access while pending throws `LogicException`. A body transfer
failure is also reported through the trailer state.

## Dependencies

The message layer depends on `psr/http-message`. Incremental and streamed
bodies use the `smithy/async` event-loop contract.

## Design

See [HTTP Messages and Streaming Design](../../../docs/design/http/message/http-messages-and-streaming.md).

## Contributing

Run the message tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Http/Message
```
