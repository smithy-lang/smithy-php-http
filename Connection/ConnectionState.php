<?php

declare(strict_types=1);

namespace Smithy\Http\Connection;

/**
 * State machine for HTTP connection lifecycle.
 *
 * Transitions: CONNECTING to CONNECTED to SHUTTING_DOWN to CLOSED.
 * A connected transport may also move directly to CLOSED.
 */
enum ConnectionState
{
    /** TCP/TLS handshake in progress. */
    case CONNECTING;

    /** Connection established and ready for requests. */
    case CONNECTED;

    /** Graceful shutdown initiated (e.g. HTTP/2 GOAWAY received). */
    case SHUTTING_DOWN;

    /** Connection closed. Handle is no longer usable. */
    case CLOSED;
}
