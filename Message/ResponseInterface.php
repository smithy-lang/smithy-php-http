<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Define the HTTP response contract returned by Smithy transports.
 */
interface ResponseInterface extends \Psr\Http\Message\ResponseInterface
{
    /**
     * Discard unread body bytes while preserving transport reuse.
     *
     * @return void
     */
    public function discardBody(): void;

    /**
     * Get the shared response trailer state.
     */
    public function getTrailers(): ResponseTrailers;
}
