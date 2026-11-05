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
    asset_name: '…'                  # EIP-712 domain name of that contract, sent as extra.name
    asset_version: '…'               # EIP-712 domain version, sent as extra.version
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
- On an `eip155:` network, `enabled: true` also needs `asset`, `asset_name`
  and `asset_version`. The `exact` scheme signs an EIP-3009 authorisation
  against the token's EIP-712 domain, so a 402 without `extra.name` and
  `extra.version` asks for a payment no client can sign.
- `network` must be a CAIP-2 chain id and every `amount` digits only; anything
  else fails the container build.
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

`verify()` checks the `PAYMENT-SIGNATURE` header (a base64-encoded x402 v2
`PaymentPayload`) against the requirement and throws
`PaymentRequiredException` when it is missing or invalid. `settle()` runs only
after the controller produced a **2xx** response — a request that ends in an
error has delivered nothing and is not charged. A settlement failure at that
point is logged and does not change the response; the caller already has what
they paid for. A string returned from `settle()` — the base64-encoded
`SettlementResponse` — is sent as `PAYMENT-RESPONSE`.

### Letting authenticated agents through

Implement `PaymentBypassVoterInterface` from `koh/sylius-agent-contracts` to
exempt callers, for example agents holding an MCP access token. The listener
runs at priority 5, after the Symfony firewall (8), so the security token
exists when voters are asked. Base the decision on that token, never on a
request header the caller controls.

## Output

The bundle speaks x402 protocol version 2 over HTTP
([`specs/x402-specification-v2.md`](https://github.com/coinbase/x402/blob/main/specs/x402-specification-v2.md),
[`specs/transports-v2/http.md`](https://github.com/coinbase/x402/blob/main/specs/transports-v2/http.md)).
A gated request without a valid payment gets the `PaymentRequired` object
base64-encoded in the `PAYMENT-REQUIRED` header, and the same object as the
JSON body:

```http
HTTP/1.1 402 Payment Required
Content-Type: application/json
Cache-Control: no-store
Access-Control-Allow-Origin: *
Access-Control-Expose-Headers: PAYMENT-REQUIRED
PAYMENT-REQUIRED: eyJ4NDAyVmVyc2lvbiI6Mi…

{
  "x402Version": 2,
  "error": "This resource advertises payment requirements but settlement is not configured.",
  "resource": {
    "url": "https://shop.example.com/agent/feed",
    "description": "Product feed",
    "mimeType": "application/json"
  },
  "accepts": [{
    "scheme": "exact",
    "network": "eip155:84532",
    "amount": "10000",
    "asset": "0x…",
    "payTo": "0x…",
    "maxTimeoutSeconds": 60,
    "extra": {"name": "…", "version": "…"}
  }]
}
```

A 402 is never cached: the requirement is per request, and a cached challenge
would keep answering after payment.

The same price table feeds `PayableOperationProviderInterface`, under the rail
`x402`. That is not an MPP payment method, so `koh/sylius-mpp` does not declare
these operations as MPP `x-payment-info` offers; the 402 above is where an
x402 client learns the price. While `enabled` is false nothing is published at
all.

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
