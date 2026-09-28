<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

use Symfony\Component\HttpFoundation\Request;

/**
 * Advertises payment requirements without settling anything.
 *
 * The default, and deliberately so. It makes the shop protocol-correct — the
 * 402 body is exactly what a paying verifier would demand — while no funds move
 * and no wallet key is needed. A shop can publish its prices and be discovered
 * long before anyone decides whether to accept stablecoins.
 *
 * It never grants access. Anything behind it stays unreachable to a paying
 * client until a real verifier is configured, which is the honest failure mode:
 * refusing a valid payment is recoverable, granting access for an unverified
 * one is not.
 */
final class NullPaymentVerifier implements PaymentVerifierInterface
{
    public function verify(Request $request, PaymentRequirement $requirement): VerifiedPayment
    {
        throw new PaymentRequiredException(
            $requirement,
            'This resource advertises payment requirements but settlement is not configured.',
        );
    }

    public function settle(VerifiedPayment $payment): ?string
    {
        return null;
    }
}
