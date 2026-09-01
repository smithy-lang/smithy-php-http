<?php

declare(strict_types=1);

namespace Smithy\Http\Client\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown for invalid requests.
 */
class RequestException extends \InvalidArgumentException implements
    RequestExceptionInterface
{
    public function __construct(
        string $message,
        public readonly RequestInterface $request,
        ?\Throwable $previous = null
    )
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Return the request associated with the failure.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
