<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402\Tests;

use Koh\SyliusX402\KohSyliusX402Bundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * This is the configuration that decides whether the shop charges money and who
 * receives it. Two failures matter more than the rest: charging by accident
 * after a plain install, and gating a resource with no payee — which refuses
 * access and collects nothing, the worst of both outcomes.
 */
#[CoversClass(KohSyliusX402Bundle::class)]
final class BundleConfigurationTest extends TestCase
{
    public function testInstallingTheBundleDoesNotStartCharging(): void
    {
        // A default of true would silently put a price on API routes the moment
        // the package lands in a deploy.
        $parameters = $this->parameters();

        self::assertFalse($parameters['koh_x402.enabled']);
        self::assertSame([], $parameters['koh_x402.resources']);
    }

    public function testEnablingWithoutAPayeeIsRefusedAtBuildTime(): void
    {
        // Advertising a price with nowhere to send the money denies access and
        // collects nothing. Failing the container build is the right moment to
        // catch it — the alternative is discovering it from missing revenue.
        try {
            $this->parameters([
                'enabled' => true,
                'pay_to' => '',
                'resources' => [['pattern' => '^/api/agent/feed', 'amount' => '10000']],
            ]);
            self::fail('Expected the container build to be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('pay_to', $exception->getMessage());
        }
    }

    public function testAPayeeIsNotRequiredWhileTheGateIsOff(): void
    {
        // Operators stage the price table before switching the gate on; that
        // must not be blocked.
        $parameters = $this->parameters([
            'enabled' => false,
            'pay_to' => '',
            'resources' => [['pattern' => '^/api/agent/feed', 'amount' => '10000']],
        ]);

        self::assertFalse($parameters['koh_x402.enabled']);
        self::assertCount(1, $parameters['koh_x402.resources']);
    }

    public function testEnablingWithAPayeeIsAccepted(): void
    {
        $parameters = $this->parameters([
            'enabled' => true,
            'pay_to' => '0xPayee',
            'resources' => [['pattern' => '^/api/agent/feed', 'amount' => '10000']],
        ]);

        self::assertTrue($parameters['koh_x402.enabled']);
        self::assertSame('0xPayee', $parameters['koh_x402.pay_to']);
    }

    public function testADisabledResourceIsNotGated(): void
    {
        // Switching a single rule off is how an operator stops charging for one
        // route. If the flag were ignored, the route would keep demanding money.
        $parameters = $this->parameters([
            'enabled' => true,
            'pay_to' => '0xPayee',
            'resources' => [
                ['pattern' => '^/api/agent/feed', 'amount' => '10000', 'enabled' => false],
                ['pattern' => '^/api/agent/other', 'amount' => '500'],
            ],
        ]);

        self::assertCount(1, $parameters['koh_x402.resources']);
        self::assertSame('^/api/agent/other', $parameters['koh_x402.resources'][0]['pattern']);
    }

    public function testResourcesAreEnabledUnlessSaidOtherwise(): void
    {
        $parameters = $this->parameters([
            'enabled' => true,
            'pay_to' => '0xPayee',
            'resources' => [['pattern' => '^/api/agent/feed', 'amount' => '10000']],
        ]);

        self::assertCount(1, $parameters['koh_x402.resources']);
    }

    public function testTheDefaultNetworkIsATestnet(): void
    {
        // Base Sepolia. Defaulting to a mainnet chain id would point real funds
        // at a shop that has not been reviewed for it.
        self::assertSame('eip155:84532', $this->parameters()['koh_x402.network']);
    }

    public function testTheNetworkCanBeOverridden(): void
    {
        $parameters = $this->parameters(['network' => 'eip155:8453']);

        self::assertSame('eip155:8453', $parameters['koh_x402.network']);
    }

    public function testNoSettlementAssetIsAssumed(): void
    {
        // An assumed asset address would be someone else's contract.
        self::assertSame('', $this->parameters()['koh_x402.asset']);
        self::assertSame('', $this->parameters()['koh_x402.pay_to']);
    }

    public function testAResourceWithoutAPatternIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->parameters(['resources' => [['amount' => '10000']]]);
    }

    public function testAResourceWithoutAnAmountIsRejected(): void
    {
        // A rule with no price would gate the route and demand nothing,
        // which no client can satisfy.
        $this->expectException(InvalidConfigurationException::class);

        $this->parameters(['resources' => [['pattern' => '^/api/agent/feed']]]);
    }

    public function testMethodsDefaultToEveryMethod(): void
    {
        // Empty means all. Gating GET while leaving HEAD free would give away a
        // resource's existence and size for nothing.
        $parameters = $this->parameters([
            'resources' => [['pattern' => '^/api/agent/feed', 'amount' => '10000']],
        ]);

        self::assertSame([], $parameters['koh_x402.resources'][0]['methods']);
    }

    public function testConfiguredMethodsSurviveIntoTheParameter(): void
    {
        $parameters = $this->parameters([
            'resources' => [['pattern' => '^/_mcp$', 'amount' => '500', 'methods' => ['POST']]],
        ]);

        self::assertSame(['POST'], $parameters['koh_x402.resources'][0]['methods']);
    }

    public function testTheExtensionAliasIsStable(): void
    {
        // Operators write this key in config/packages; renaming it silently
        // discards their price table and the gate falls back to disabled.
        self::assertSame('koh_x402', $this->extension()->getAlias());
    }

    public function testTheBundlePathIsThePackageRootSoConfigResolves(): void
    {
        $bundle = new KohSyliusX402Bundle();

        self::assertDirectoryExists($bundle->getPath() . '/config');
        self::assertFileExists($bundle->getPath() . '/composer.json');
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function parameters(array $config = []): array
    {
        $builder = $this->containerBuilder();
        $this->extension()->load([$config], $builder);

        return $builder->getParameterBag()->all();
    }

    private function extension(): ExtensionInterface
    {
        $extension = (new KohSyliusX402Bundle())->getContainerExtension();
        self::assertInstanceOf(ExtensionInterface::class, $extension);

        return $extension;
    }

    private function containerBuilder(): ContainerBuilder
    {
        return new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => true,
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => \dirname(__DIR__),
        ]));
    }
}
