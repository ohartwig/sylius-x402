<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\EventListener;

use Koh\SyliusAgentContracts\Provider\PaymentBypassVoterInterface;
use Koh\SyliusX402\Config\RequirementTable;
use Koh\SyliusX402\Payment\PaymentRequiredException;
use Koh\SyliusX402\Payment\PaymentVerifierInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Answers a gated request with 402 unless it carries a valid payment.
 *
 * Registered at priority 5, below Symfony's firewall (8), so the security token
 * exists by the time the bypass voters run. Above the firewall, "an
 * authenticated agent does not pay" could not be expressed at all.
 */
final class X402RequestListener
{
    public const PAYMENT_ATTRIBUTE = '_koh_x402_payment';

    /** @param iterable<PaymentBypassVoterInterface> $bypassVoters */
    public function __construct(
        private readonly RequirementTable $requirements,
        private readonly PaymentVerifierInterface $verifier,
        private readonly iterable $bypassVoters = [],
        private readonly bool $enabled = false,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest() || $this->requirements->isEmpty()) {
            return;
        }

        $request = $event->getRequest();
        $requirement = $this->requirements->match($request->getPathInfo(), $request->getMethod());
        if (null === $requirement) {
            return;
        }

        foreach ($this->bypassVoters as $voter) {
            if ($voter->shouldBypass($request)) {
                return;
            }
        }

        try {
            $request->attributes->set(
                self::PAYMENT_ATTRIBUTE,
                $this->verifier->verify($request, $requirement),
            );
        } catch (PaymentRequiredException $exception) {
            $event->setResponse($this->challenge($exception, $request->getUri()));
        }
    }

    private function challenge(PaymentRequiredException $exception, string $resource): Response
    {
        $response = new JsonResponse([
            'x402Version' => 1,
            'error' => $exception->getMessage(),
            'accepts' => [$exception->requirement->toAccepts($resource)],
        ], Response::HTTP_PAYMENT_REQUIRED);

        // A price is public information and agents read it cross-origin.
        $response->headers->set('Access-Control-Allow-Origin', '*');

        // Never cache a 402: the requirement is per-request and a cached
        // challenge would keep serving after payment.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
