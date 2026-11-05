<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Payment;

use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies and settles an x402 payment.
 *
 * An interface because the on-chain half is provided by a third-party library
 * whose API is young and whose scope is narrow. Keeping it behind this seam
 * means the bundle can ship, be tested and serve correct 402 responses without
 * that dependency — and that replacing it later touches one adapter.
 */
interface PaymentVerifierInterface
{
    /**
     * Checks the base64-encoded `PaymentPayload` in the `PAYMENT-SIGNATURE`
     * request header (x402 v2) against the requirement.
     *
     * @throws PaymentRequiredException when the payment is missing or invalid
     */
    public function verify(Request $request, PaymentRequirement $requirement): VerifiedPayment;

    /**
     * @return string|null the `PAYMENT-RESPONSE` header value — a base64-encoded
     *                     x402 v2 `SettlementResponse` — if the scheme produces one
     */
    public function settle(VerifiedPayment $payment): ?string;
}
