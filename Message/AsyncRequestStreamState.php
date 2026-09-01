<?php

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Lifecycle state for an asynchronous request stream.
 *
 * @internal
 */
enum AsyncRequestStreamState
{
    /** Producer may write and consumer may read. */
    case OPEN;

    /** Producer ended normally; buffered bytes may still be read. */
    case ENDED;

    /** Producer failed; reads observe the stored failure. */
    case FAILED;

    /** Stream was closed and buffered bytes were discarded. */
    case CLOSED;
}
