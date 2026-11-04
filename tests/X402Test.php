<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Tests;

use Koh\SyliusAgentContracts\Provider\PaymentBypassVoterInterface;
use Koh\SyliusX402\Config\RequirementTableFactory;
use Koh\SyliusX402\EventListener\X402RequestListener;
use Koh\SyliusX402\EventListener\X402ResponseListener;
use Koh\SyliusX402\Payment\NullPaymentVerifier;
use Koh\SyliusX402\Payment\PaymentRequiredException;
use Koh\SyliusX402\Payment\PaymentRequirement;
use Koh\SyliusX402\Payment\PaymentVerifierInterface;
use Koh\SyliusX402\Payment\VerifiedPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class X402Test extends TestCase
{
    /** Installing the bundle must not start charging for anything. */
    public function testDisabledByDefault(): void
    {
        $event = $this->dispatch('/api/agent/feed', enabled: false);

        self::assertFalse($event->hasResponse());
    }

    public function testGatedRouteAnswers402WithPaymentRequirements(): void
    {
        $response = $this->dispatch('/api/agent/feed')->getResponse();

        self::assertNotNull($response);
        self::assertSame(402, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(1, $body['x402Version']);
        self::assertSame('10000', $body['accepts'][0]['maxAmountRequired']);
        self::assertSame('0xPayee', $body['accepts'][0]['payTo']);
        self::assertSame('eip155:84532', $body['accepts'][0]['network']);
    }

    /** A cached challenge would keep demanding payment after it was made. */
    public function testChallengeIsNeverCached(): void
    {
        $response = $this->dispatch('/api/agent/feed')->getResponse();

        self::assertStringContainsString('no-store', (string) $response?->headers->get('Cache-Control'));
    }

    /**
     * The listener has always passed the request method into match(); until now
     * match() ignored it, so a rule scoped to POST silently gated every method.
     */
    public function testAMethodScopedRuleLeavesOtherMethodsAlone(): void
    {
        $table = RequirementTableFactory::create(
            [['pattern' => '^/_mcp$', 'amount' => '500', 'methods' => ['POST']]],
            'eip155:84532',
            '0xAsset',
            '0xPayee',
        );

        self::assertNotNull($table->match('/_mcp', 'POST'));
        self::assertNull($table->match('/_mcp', 'GET'));
    }

    public function testAMethodScopedRuleIsCaseInsensitive(): void
    {
        $table = RequirementTableFactory::create(
            [['pattern' => '^/_mcp$', 'amount' => '500', 'methods' => ['post']]],
            'eip155:84532',
            '0xAsset',
            '0xPayee',
        );

        self::assertNotNull($table->match('/_mcp', 'POST'));
    }

    /**
     * Empty is every method, HEAD included: gating GET alone would hand out a
     * resource's existence and size for free.
     */
    public function testARuleWithoutMethodsGatesAllOfThem(): void
    {
        $table = RequirementTableFactory::create(
            [['pattern' => '^/api/agent/feed', 'amount' => '10000']],
            'eip155:84532',
            '0xAsset',
            '0xPayee',
        );

        foreach (['GET', 'HEAD', 'POST', 'PATCH'] as $method) {
            self::assertNotNull($table->match('/api/agent/feed', $method), $method.' slipped through');
        }
    }

    /**
     * AUD-010-F01: the router decodes the path before matching, so an encoded
     * spelling of a gated path reaches the same controller. The gate must
     * charge for what the router serves, not for how the client spelled it.
     *
     * @return iterable<string, array{string}>
     */
    public static function encodedSpellingsOfTheFeed(): iterable
    {
        yield 'plain' => ['/api/agent/feed'];
        yield 'encoded letter' => ['/api/agent/fe%65d'];
        yield 'encoded slash' => ['/api/agent%2Ffeed'];
        yield 'encoded first segment' => ['/%61pi/agent/feed'];
        yield 'lower-case escape' => ['/api/agent/fe%65%64'];
    }

    #[DataProvider('encodedSpellingsOfTheFeed')]
    public function testAnEncodedSpellingOfAGatedPathIsStillGated(string $path): void
    {
        $routes = new RouteCollection();
        $routes->add('koh_acp_product_feed', new Route('/api/agent/feed'));
        $request = Request::create($path);

        // Precondition: the router serves the feed for this spelling.
        $matched = (new UrlMatcher($routes, (new RequestContext())->fromRequest($request)))->match($request->getPathInfo());
        self::assertSame('koh_acp_product_feed', $matched['_route']);

        self::assertSame(402, $this->dispatchRequest($request)->getResponse()?->getStatusCode());
    }

    public function testUngatedRoutesAreUntouched(): void
    {
        self::assertFalse($this->dispatch('/de_DE/products/zigarre')->hasResponse());
    }

    /** Storefront and checkout must never be reachable by the gate. */
    public function testStorefrontAndCheckoutAreNotMatched(): void
    {
        foreach (['/', '/de_DE/', '/de_DE/checkout/address', '/admin/dashboard'] as $path) {
            self::assertFalse($this->dispatch($path)->hasResponse(), $path.' must not be gated');
        }
    }

    /** The bypass lives in the granting package; x402 only asks. */
    public function testBypassVoterLetsACallerThrough(): void
    {
        $voter = new class implements PaymentBypassVoterInterface {
            public function shouldBypass(Request $request): bool
            {
                return $request->headers->has('Authorization');
            }
        };

        $request = Request::create('/api/agent/feed');
        $request->headers->set('Authorization', 'Bearer token');

        self::assertFalse($this->dispatchRequest($request, voters: [$voter])->hasResponse());
    }

    /**
     * The default verifier advertises but never grants. Refusing a valid
     * payment is recoverable; granting access for an unverified one is not.
     */
    public function testNullVerifierNeverGrantsAccess(): void
    {
        $this->expectException(PaymentRequiredException::class);

        (new NullPaymentVerifier())->verify(new Request(), $this->requirement());
    }

    /** A failed request delivered nothing, so charging for it would be theft. */
    public function testSettlementOnlyHappensOnSuccess(): void
    {
        $verifier = $this->countingVerifier();

        $this->settle($verifier, new Response('', 500));
        self::assertSame(0, $verifier->settled);

        $this->settle($verifier, new Response('{}', 200));
        self::assertSame(1, $verifier->settled);
    }

    public function testReceiptIsReturnedToTheCaller(): void
    {
        $response = new Response('{}', 200);
        $this->settle($this->countingVerifier(), $response);

        self::assertSame('receipt-1', $response->headers->get('X-PAYMENT-RESPONSE'));
    }

    /** The caller already has their answer; a settlement failure must not take it away. */
    public function testSettlementFailureDoesNotBreakTheResponse(): void
    {
        $verifier = new class implements PaymentVerifierInterface {
            public function verify(Request $request, PaymentRequirement $requirement): VerifiedPayment
            {
                return new VerifiedPayment($requirement);
            }

            public function settle(VerifiedPayment $payment): ?string
            {
                throw new \RuntimeException('facilitator unreachable');
            }
        };

        $response = new Response('{"ok":true}', 200);
        $this->settle($verifier, $response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
    }

    private function settle(PaymentVerifierInterface $verifier, Response $response): void
    {
        $request = Request::create('/api/agent/feed');
        $request->attributes->set(
            X402RequestListener::PAYMENT_ATTRIBUTE,
            new VerifiedPayment($this->requirement()),
        );

        (new X402ResponseListener($verifier))(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));
    }

    private function countingVerifier(): PaymentVerifierInterface
    {
        return new class implements PaymentVerifierInterface {
            public int $settled = 0;

            public function verify(Request $request, PaymentRequirement $requirement): VerifiedPayment
            {
                return new VerifiedPayment($requirement);
            }

            public function settle(VerifiedPayment $payment): ?string
            {
                ++$this->settled;

                return 'receipt-'.$this->settled;
            }
        };
    }

    /** @param list<PaymentBypassVoterInterface> $voters */
    private function dispatch(string $path, bool $enabled = true, array $voters = []): RequestEvent
    {
        return $this->dispatchRequest(Request::create($path), $enabled, $voters);
    }

    /** @param list<PaymentBypassVoterInterface> $voters */
    private function dispatchRequest(Request $request, bool $enabled = true, array $voters = []): RequestEvent
    {
        $table = RequirementTableFactory::create(
            [['pattern' => '^/api/agent/feed', 'amount' => '10000', 'description' => 'Product feed']],
            'eip155:84532',
            '0xAsset',
            '0xPayee',
        );

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new X402RequestListener($table, new NullPaymentVerifier(), $voters, $enabled))($event);

        return $event;
    }

    private function requirement(): PaymentRequirement
    {
        return new PaymentRequirement('^/api/agent/feed', '10000', 'eip155:84532', '0xAsset', '0xPayee');
    }
}
