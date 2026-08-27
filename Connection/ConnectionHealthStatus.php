<?php

declare(strict_types=1);

namespace Smithy\Http\Connection;

/**
 * Health status of an HTTP connection.
 *
 * Used by the ConnectionManager to decide whether a pooled
 * connection should be reused or discarded.
 */
enum ConnectionHealthStatus
{
    /** Connection is known to be usable. */
    case HEALTHY;

    /** Connection experienced errors or throughput degradation. */
    case UNHEALTHY;

    /** Health has not been determined (e.g. freshly created). */
    case UNKNOWN;
}
