<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

use Smithy\Client\Transport\MessageExchangeInterface;

/**
 * PSR-7 request and response exchange used by HTTP protocols and transports.
 */
final class HttpMessageExchange implements MessageExchangeInterface
{
    private static ?self $instance = null;

    /**
     * Return the shared HTTP message exchange.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @inheritDoc */
    public function getRequestType(): string
    {
        return HttpTransportRequest::class;
    }

    /** @inheritDoc */
    public function getResponseType(): string
    {
        return HttpTransportResponse::class;
    }

    /** @inheritDoc */
    public function isCompatibleWith(
        MessageExchangeInterface $exchange
    ): bool
    {
        return $exchange->getRequestType() === $this->getRequestType()
            && $exchange->getResponseType() === $this->getResponseType();
    }

    private function __construct() {}
}
