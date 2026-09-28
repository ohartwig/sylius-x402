<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\EventListener;

use Koh\SyliusX402\Payment\PaymentVerifierInterface;
use Koh\SyliusX402\Payment\VerifiedPayment;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Settles the payment once the response is known.
 *
 * Settlement belongs after the controller: a request that ends in a 500 has
 * delivered nothing, and charging for it would be theft. Only a successful
 * response settles.
 */
final class X402ResponseListener
{
    public function __construct(
        private readonly PaymentVerifierInterface $verifier,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $payment = $event->getRequest()->attributes->get(X402RequestListener::PAYMENT_ATTRIBUTE);
        if (!$payment instanceof VerifiedPayment) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }

        try {
            $receipt = $this->verifier->settle($payment);
            if (null !== $receipt) {
                $response->headers->set('X-PAYMENT-RESPONSE', $receipt);
            }
        } catch (\Throwable $exception) {
            // The caller already has their answer; failing the response now
            // would deny them what they paid for. Settlement problems are an
            // operational matter, so they go to the log.
            $this->logger?->error('x402 settlement failed after the response was produced.', [
                'exception' => $exception,
            ]);
        }
    }
}
