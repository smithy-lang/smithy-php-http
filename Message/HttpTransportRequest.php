<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Psr\Http\Message\RequestInterface;
use Smithy\Client\Endpoint\Endpoint;
use Smithy\Client\Transport\TransportRequestInterface;

/**
 * Transport-neutral envelope containing one PSR-7 HTTP request.
 */
final readonly class HttpTransportRequest implements
    TransportRequestInterface
{
    public function __construct(
        public RequestInterface $request
    ) {}

    /**
     * Require an HTTP request envelope.
     */
    public static function expect(
        TransportRequestInterface $request
    ): self
    {
        if ($request instanceof self) {
            return $request;
        }

        throw new \InvalidArgumentException(
            'Expected transport request of type ' . self::class
                . ', got ' . $request::class . '.'
        );
    }

    /**
     * Return an envelope containing a replacement PSR-7 request.
     */
    public function withRequest(RequestInterface $request): self
    {
        return $request === $this->request ? $this : new self($request);
    }

    /**
     * Apply endpoint headers to the enclosed HTTP request.
     */
    public function withEndpointHeaders(Endpoint $endpoint): self
    {
        $headers = $endpoint->headers;
        if ($headers === []) {
            return $this;
        }

        $rawHeaders = [];
        foreach ($headers as $name => $value) {
            $value = (string) $value;
            HttpMessageValidator::validateHeaderName($name);
            HttpMessageValidator::validateHeaderValue($value);
            $rawHeaders[\strtolower($name)] = [$name, [$value]];
        }

        $request = $this->request;
        if ($request instanceof Request) {
            return $this->withRequest(
                $request->withNormalizedHeaders($rawHeaders)
            );
        }

        foreach ($rawHeaders as [$name, $values]) {
            $request = $request->withHeader($name, $values);
        }

        return $this->withRequest($request);
    }
}
