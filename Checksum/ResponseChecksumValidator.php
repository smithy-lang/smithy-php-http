<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Http\Checksum;

use Smithy\Core\Checksum\ChecksumAlgorithm;

/**
 * Choose which response checksum to validate.
 *
 * The flexible-checksums SEP defines the priority order for response
 * validation: the client validates the first algorithm it supports in this
 * order, fastest first, regardless of the order the response lists them.
 */
final class ResponseChecksumValidator
{
    /**
     * Response validation priority, fastest first, from the flexible-checksums
     * SEP.
     *
     * @var list<ChecksumAlgorithm>
     */
    public const RESPONSE_VALIDATION_ORDER = [
        ChecksumAlgorithm::CRC32C,
        ChecksumAlgorithm::CRC32,
        ChecksumAlgorithm::XXHASH64,
        ChecksumAlgorithm::XXHASH3,
        ChecksumAlgorithm::XXHASH128,
        ChecksumAlgorithm::SHA1,
        ChecksumAlgorithm::SHA256,
        ChecksumAlgorithm::SHA512,
    ];

    /**
     * Return the modeled algorithms the client can validate, in priority
     * order. An algorithm is kept when the service models it and the runtime
     * supports it.
     *
     * @param list<ChecksumAlgorithm> $modeled algorithms the service models
     *
     * @return list<ChecksumAlgorithm>
     */
    public static function candidates(array $modeled): array
    {
        $wanted = [];
        foreach ($modeled as $algorithm) {
            $wanted[$algorithm->value] = true;
        }

        $candidates = [];
        foreach (self::RESPONSE_VALIDATION_ORDER as $algorithm) {
            if (isset($wanted[$algorithm->value]) && $algorithm->isSupported()) {
                $candidates[] = $algorithm;
            }
        }

        return $candidates;
    }
}
