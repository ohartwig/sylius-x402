<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
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

    /** x402 protocol version spoken on the wire. */
    public const X402_VERSION = 2;

    /** Server to client: base64-encoded `PaymentRequired`. */
    public const PAYMENT_REQUIRED_HEADER = 'PAYMENT-REQUIRED';

    /** Client to server: base64-encoded `PaymentPayload`, read by the verifier. */
    public const PAYMENT_SIGNATURE_HEADER = 'PAYMENT-SIGNATURE';

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
        // Match what the router matches. getPathInfo() is the raw path, while
        // Symfony's UrlMatcher decodes it first (rawurldecode($pathinfo)), so
        // /api/agent/fe%65d would reach the feed without ever matching
        // ^/api/agent/feed here. (The matcher's "?: '/'" fallback cannot apply:
        // path info always starts with a slash.)
        $requirement = $this->requirements->match(rawurldecode($request->getPathInfo()), $request->getMethod());
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

    /**
     * The x402 v2 `PaymentRequired` answer (specs/x402-specification-v2.md 5.1,
     * specs/transports-v2/http.md "Payment Required Signaling").
     *
     * The canonical carrier is the base64-encoded `PAYMENT-REQUIRED` header;
     * the body repeats the same object as JSON, which the HTTP transport leaves
     * to the server, so a client reading only the body still learns the price.
     *
     * @param string $resource the gated URI, echoed back so the payer knows what
     *                         they are paying for
     *
     * Built from the request rather than from AgentContext, unlike the rest of
     * this package family. That makes it the one URL here a caller can steer via
     * the Host header. It is left that way deliberately: this response goes back
     * to whoever sent the request and carries no-store, so a spoofed host
     * misleads only the spoofer. Worth aligning when this listener next changes
     * — it would mean taking an AgentContextFactory the listener does not
     * currently need.
     */
    private function challenge(PaymentRequiredException $exception, string $resource): Response
    {
        $paymentRequired = [
            'x402Version' => self::X402_VERSION,
            'error' => $exception->getMessage(),
            'resource' => $exception->requirement->toResourceInfo($resource),
            'accepts' => [$exception->requirement->toAccepts()],
        ];

        $json = json_encode($paymentRequired, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $response = new JsonResponse($json, Response::HTTP_PAYMENT_REQUIRED, [], true);
        $response->headers->set(self::PAYMENT_REQUIRED_HEADER, base64_encode($json));

        // A price is public information and agents read it cross-origin; a
        // browser hides any response header not listed here.
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Expose-Headers', self::PAYMENT_REQUIRED_HEADER);

        // Never cache a 402: the requirement is per-request and a cached
        // challenge would keep serving after payment.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
