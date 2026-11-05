<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusX402;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * x402 payment middleware for API routes.
 */
final class KohSyliusX402Bundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_x402';

    /** CAIP-2 chain id: namespace:reference. */
    private const CAIP2 = '/^[-a-z0-9]{3,8}:[-_a-zA-Z0-9]{1,32}\z/';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Off by default. Installing this bundle must not start charging for anything.')
                ->end()
                ->scalarNode('network')
                    ->defaultValue('eip155:84532')
                    ->info('CAIP-2 chain id. Default is Base Sepolia — a testnet.')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !\is_string($v) || 1 !== preg_match(self::CAIP2, $v))
                        ->thenInvalid('koh_x402.network must be a CAIP-2 chain id such as "eip155:8453", got %s.')
                    ->end()
                ->end()
                ->scalarNode('asset')
                    ->defaultValue('')
                    ->info('Contract address of the settlement asset.')
                ->end()
                ->scalarNode('asset_name')
                    ->defaultValue('')
                    ->info('EIP-712 domain name of the asset contract (e.g. "USDC"), sent as extra.name. Required on eip155 networks: the exact scheme cannot be signed without it.')
                ->end()
                ->scalarNode('asset_version')
                    ->defaultValue('')
                    ->info('EIP-712 domain version of the asset contract (e.g. "2"), sent as extra.version. Required on eip155 networks.')
                ->end()
                ->scalarNode('pay_to')
                    ->defaultValue('')
                    ->info('Receiving address.')
                ->end()
                ->arrayNode('resources')
                    ->info('Paths to gate. Never the storefront or the checkout.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('pattern')->isRequired()->info('Regex against the path.')->end()
                            ->scalarNode('amount')
                                ->isRequired()
                                ->info('Smallest unit, as digits.')
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => !\is_string($v) || 1 !== preg_match('/^[0-9]+\z/', $v))
                                    ->thenInvalid('koh_x402 resource amounts are digits in the smallest unit of the asset, got %s.')
                                ->end()
                            ->end()
                            ->scalarNode('description')->defaultNull()->end()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->arrayNode('methods')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                                ->info('HTTP methods this rule gates. Empty means all — gating GET but leaving HEAD free would give away existence and size for nothing.')
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $resources = [];
        foreach ($config['resources'] ?? [] as $resource) {
            if (\is_array($resource) && false !== ($resource['enabled'] ?? true)) {
                $resources[] = $resource;
            }
        }

        // Refuse to gate anything without a payee. Advertising a price with no
        // address to pay would collect nothing while denying access.
        $payTo = (string) ($config['pay_to'] ?? '');
        $enabled = (bool) ($config['enabled'] ?? false);
        if ($enabled && '' === $payTo) {
            throw new \InvalidArgumentException(
                'koh_x402.pay_to must be set when koh_x402.enabled is true — otherwise the shop would refuse access and collect nothing.',
            );
        }

        $network = (string) ($config['network'] ?? 'eip155:84532');
        $asset = (string) ($config['asset'] ?? '');
        $extra = [];
        if (str_starts_with($network, 'eip155:')) {
            // The exact scheme on EVM signs an EIP-3009 authorisation against the
            // token's EIP-712 domain; extra.name and extra.version are required
            // (specs/schemes/exact/scheme_exact_evm.md). A 402 without them asks
            // for a payment no client can sign.
            $name = (string) ($config['asset_name'] ?? '');
            $version = (string) ($config['asset_version'] ?? '');
            if ($enabled && ('' === $asset || '' === $name || '' === $version)) {
                throw new \InvalidArgumentException(
                    'koh_x402.asset, koh_x402.asset_name and koh_x402.asset_version must be set when koh_x402.enabled is true on an eip155 network — otherwise the 402 asks for a payment no client can sign.',
                );
            }
            if ('' !== $name && '' !== $version) {
                $extra = ['name' => $name, 'version' => $version];
            }
        }

        $builder->setParameter('koh_x402.enabled', $enabled);
        $builder->setParameter('koh_x402.network', $network);
        $builder->setParameter('koh_x402.asset', $asset);
        $builder->setParameter('koh_x402.extra', $extra);
        $builder->setParameter('koh_x402.pay_to', $payTo);
        $builder->setParameter('koh_x402.resources', $resources);

        $container->import('../config/services.php');
    }
}
