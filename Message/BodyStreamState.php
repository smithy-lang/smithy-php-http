<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Message;

/**
 * Lifecycle state for an asynchronous request or streaming response body.
 *
 * @internal
 */
enum BodyStreamState
{
    /** Producer may append and consumer may read. */
    case OPEN;

    /** Producer ended normally. Buffered bytes may still be read. */
    case ENDED;

    /** Producer failed. Reads end with the stored failure. */
    case FAILED;

    /** Stream was closed and buffered bytes were discarded. */
    case CLOSED;
}
