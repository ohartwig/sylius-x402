<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Tests;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\PayableOperation;
use Koh\SyliusX402\Agent\X402PayableOperationProvider;
use Koh\SyliusX402\Config\RequirementTable;
use Koh\SyliusX402\Config\RequirementTableFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * This provider is what a client reads before deciding to pay. If it disagrees
 * with the table the request listener enforces, the shop advertises one price
 * and charges another — so the tests here are mostly about the two staying the
 * same thing rather than about the shape of the output.
 */
#[CoversClass(X402PayableOperationProvider::class)]
final class X402PayableOperationProviderTest extends TestCase
{
    public function testNothingIsAdvertisedWhileTheGateIsOff(): void
    {
        // Publishing a price the shop will not enforce invites a client to
        // prepare a payment that the request listener then ignores.
        $provider = new X402PayableOperationProvider($this->table(), enabled: false);

        self::assertSame([], iterator_to_array($provider->getPayableOperations($this->context())));
    }

    public function testEveryGatedResourceIsAdvertised(): void
    {
        $provider = new X402PayableOperationProvider(
            $this->table([
                ['pattern' => '^/api/agent/feed$', 'amount' => '10000'],
                ['pattern' => '^/_mcp$', 'amount' => '500', 'methods' => ['POST']],
            ]),
            enabled: true,
        );

        $operations = iterator_to_array($provider->getPayableOperations($this->context()));

        self::assertCount(2, $operations);
        self::assertContainsOnlyInstancesOf(PayableOperation::class, $operations);
    }

    public function testTheAdvertisedAmountIsTheAmountThatWillBeCharged(): void
    {
        // One table feeds both the declaration and the enforcement; this asserts
        // the value survives the trip rather than being re-derived.
        $table = $this->table([['pattern' => '^/api/agent/feed$', 'amount' => '10000']]);
        $provider = new X402PayableOperationProvider($table, enabled: true);

        $operation = iterator_to_array($provider->getPayableOperations($this->context()))[0];
        $enforced = $table->match('/api/agent/feed');

        self::assertNotNull($enforced);
        self::assertSame($enforced->amount, $operation->amount);
        self::assertSame($enforced->asset, $operation->currency);
    }

    public function testTheAdvertisedPathIsRequestableRatherThanARegex(): void
    {
        // The table stores anchored regexes. A client cannot request "^/x$", so
        // the anchors are stripped for the declaration.
        $provider = new X402PayableOperationProvider(
            $this->table([['pattern' => '^/api/agent/feed$', 'amount' => '10000']]),
            enabled: true,
        );

        $operation = iterator_to_array($provider->getPayableOperations($this->context()))[0];

        self::assertSame('/api/agent/feed', $operation->path);
    }

    public function testTheAdvertisedPathStillMatchesTheEnforcingRule(): void
    {
        // The strongest form of the agreement: take the advertised path, feed it
        // back to the table, and it must be the rule that was advertised.
        $table = $this->table([
            ['pattern' => '^/api/agent/feed$', 'amount' => '10000'],
            ['pattern' => '^/_mcp$', 'amount' => '500'],
        ]);
        $provider = new X402PayableOperationProvider($table, enabled: true);

        foreach ($provider->getPayableOperations($this->context()) as $operation) {
            $matched = $table->match($operation->path);

            self::assertNotNull($matched, $operation->path . ' is advertised but not gated');
            self::assertSame($matched->amount, $operation->amount);
        }
    }

    public function testAPatternWithoutALeadingSlashStillProducesAnAbsolutePath(): void
    {
        $provider = new X402PayableOperationProvider(
            $this->table([['pattern' => 'api/agent/feed', 'amount' => '10000']]),
            enabled: true,
        );

        $operation = iterator_to_array($provider->getPayableOperations($this->context()))[0];

        self::assertSame('/api/agent/feed', $operation->path);
    }

    public function testThePaymentMethodAndIntentIdentifyTheProtocol(): void
    {
        // A client picks its wallet integration from these two fields; a wrong
        // value means it cannot pay at all.
        $provider = new X402PayableOperationProvider($this->table(), enabled: true);

        $operation = iterator_to_array($provider->getPayableOperations($this->context()))[0];

        self::assertSame('x402', $operation->payMethod);
        self::assertSame('charge', $operation->intent);
    }

    public function testTheDescriptionIsCarriedThrough(): void
    {
        $provider = new X402PayableOperationProvider(
            $this->table([[
                'pattern' => '^/api/agent/feed$',
                'amount' => '10000',
                'description' => 'Full product feed',
            ]]),
            enabled: true,
        );

        $operation = iterator_to_array($provider->getPayableOperations($this->context()))[0];

        self::assertSame('Full product feed', $operation->description);
    }

    public function testAnEmptyTableAdvertisesNothingEvenWhenEnabled(): void
    {
        $provider = new X402PayableOperationProvider(new RequirementTable(), enabled: true);

        self::assertSame([], iterator_to_array($provider->getPayableOperations($this->context())));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param list<array{pattern: string, amount: string, description?: string|null, methods?: list<string>}> $resources
     */
    private function table(array $resources = [['pattern' => '^/api/agent/feed$', 'amount' => '10000']]): RequirementTable
    {
        return RequirementTableFactory::create($resources, 'eip155:84532', '0xAsset', '0xPayee');
    }

    private function context(): AgentContext
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, 'https://shop.example', 'de_DE', ['de_DE']);
    }
}
