<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Lifecycle state for a streaming response body.
 *
 * @internal
 */
enum StreamingResponseStreamState
{
    /** Transport may append and consumer may read. */
    case OPEN;

    /** Transport ended normally; buffered bytes may still be read. */
    case ENDED;

    /** Transport failed; buffered bytes may still be read first. */
    case FAILED;

    /** Stream was closed and buffered bytes were discarded. */
    case CLOSED;
}
