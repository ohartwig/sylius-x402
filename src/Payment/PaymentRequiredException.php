<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

/**
 * Carries everything the 402 response needs.
 */
final class PaymentRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly PaymentRequirement $requirement,
        string $reason = 'Payment required.',
    ) {
        parent::__construct($reason);
    }
}
