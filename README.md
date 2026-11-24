# Smithy PHP HTTP

PSR-7 messages and a PSR-18 cURL client for Smithy PHP, with fiber-aware
concurrency, bounded body streaming, cancellation, and response checksum
validation. Requires PHP 8.2 or later and the cURL extension.

This repository is a read-only split of
[smithy-lang/smithy-php](https://github.com/smithy-lang/smithy-php). Send
issues and pull requests to the monorepo.

## Installation

```shell
composer require smithy/http
```

## Sending Requests

```php
use Smithy\Http\Client\CurlClient;
use Smithy\Http\Message\Request;

$client = new CurlClient();
$response = $client->sendRequest(
    new Request('GET', 'https://example.com/status')
);

echo $response->getStatusCode();
```

## Components

| Component | Responsibility |
|---|---|
| [Client](Client/README.md) | `CurlClient`, connection limits, TLS, cancellation, and trailers |
| [Message](Message/README.md) | PSR-7 request, response, URI, and stream types |
| `Checksum` | Response checksum validation for streamed bodies |

## Dependencies

This package builds on `smithy/async`, `smithy/core`, `psr/http-client`, and
`psr/http-message`.

## Design

See [HTTP Runtime Design](../../docs/design/http/http-runtime-design.md).

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Http
```
