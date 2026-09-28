<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
SPDX-License-Identifier: MIT
-->

# koh/sylius-x402

[x402](https://www.x402.org/) payment middleware for Sylius: selected API paths
answer `402 Payment Required` with a machine-readable price that an agent can
pay and retry.

It is **off by default**. Installing the bundle must not start charging for
anything, and even when switched on it ships no settlement: the bundled
verifier advertises prices and refuses every payment until you plug in a real
one. That makes a shop protocol-correct and discoverable long before anyone
decides to accept stablecoins.

## Install

```bash
composer require koh/sylius-x402
```

```php
// config/bundles.php
Koh\SyliusX402\KohSyliusX402Bundle::class => ['all' => true],
```

`koh/sylius-agent-contracts` comes with it. There is no route to import — the
bundle works as request and response listeners.

## Configure

```yaml
# config/packages/koh_x402.yaml
koh_x402:
    enabled: true
    network: 'eip155:84532'          # CAIP-2 chain id; default is Base Sepolia, a testnet
    asset: '0x…'                     # contract address of the settlement asset
    pay_to: '0x…'                    # receiving address — required when enabled
    resources:
        - pattern: '^/agent/feed'    # regex against the path
          amount: '10000'            # smallest unit of the asset, digits only
          description: 'Product feed'
        - pattern: '^/_mcp'
          amount: '1000'
          methods: ['POST']          # GET stays a free capability probe
```

- `enabled: true` without `pay_to` fails the container build. A price with no
  address to pay would deny access and collect nothing.
- `methods` is empty by default, which gates **every** method. Gating `GET`
  but leaving `HEAD` free would reveal that a resource exists, and its size,
  without payment.
- Gate API paths only — never the storefront or the checkout.

### Settling payments

Alias `PaymentVerifierInterface` to your own implementation:

```yaml
services:
    Koh\SyliusX402\Payment\PaymentVerifierInterface: '@App\Payment\X402Verifier'
```

`verify()` checks the `X-PAYMENT` header against the requirement and throws
`PaymentRequiredException` when it is missing or invalid. `settle()` runs only
after the controller produced a **2xx** response — a request that ends in an
error has delivered nothing and is not charged. A settlement failure at that
point is logged and does not change the response; the caller already has what
they paid for. A string returned from `settle()` is sent as
`X-PAYMENT-RESPONSE`.

### Letting authenticated agents through

Implement `PaymentBypassVoterInterface` from `koh/sylius-agent-contracts` to
exempt callers, for example agents holding an MCP access token. The listener
runs at priority 5, after the Symfony firewall (8), so the security token
exists when voters are asked. Base the decision on that token, never on a
request header the caller controls.

## Output

A gated request without a valid payment:

```http
HTTP/1.1 402 Payment Required
Content-Type: application/json
Cache-Control: no-store
Access-Control-Allow-Origin: *

{
  "x402Version": 1,
  "error": "This resource advertises payment requirements but settlement is not configured.",
  "accepts": [{
    "scheme": "exact",
    "network": "eip155:84532",
    "maxAmountRequired": "10000",
    "resource": "https://shop.example.com/agent/feed",
    "description": "Product feed",
    "mimeType": "application/json",
    "payTo": "0x…",
    "maxTimeoutSeconds": 60,
    "asset": "0x…"
  }]
}
```

A 402 is never cached: the requirement is per request, and a cached challenge
would keep answering after payment.

The same price table feeds `PayableOperationProviderInterface`, so
`koh/sylius-mpp` declares it as `x-payment-info` in `/openapi.json`. A route
cannot advertise a price it does not charge, because declaration and
enforcement read the same configuration.

## Decommission

Set `enabled: false` first if agents may be mid-payment, and settle anything
outstanding in your verifier. Then remove the `composer require`, the
`config/bundles.php` entry, `config/packages/koh_x402.yaml` and any verifier
alias. The bundle owns no database table and no cache pool.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

The family threat model, covering this package, is at
<https://github.com/ohartwig/sylius-agent-ready/blob/main/docs/threat-model.md>.

## Licence

MIT — see [LICENSE](LICENSE).
