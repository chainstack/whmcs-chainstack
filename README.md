# whmcs-chainstack

A WHMCS provisioning module for [Chainstack](https://chainstack.com). It provisions and manages
Chainstack RPC/WSS node endpoints directly from WHMCS: ordering a service creates a node, the
endpoints show up in the client area, and suspend/terminate tear the resources down.

**One WHMCS service = one Chainstack node**, deployed into a per-service project inside a single
operator-owned Chainstack organization, authenticated with one admin API key.

## Requirements

- WHMCS 8.x, PHP 8.1+ (tested on 8.3). PHP extensions: `curl`, `json`, `openssl`.
- A Chainstack organization on a plan that permits the Platform API and node creation.
- A Chainstack admin **API key** (Console → Settings → API keys).

## Install

Copy the module into your WHMCS installation:

```bash
cp -r modules/servers/chainstack <whmcs>/modules/servers/
```

Then in WHMCS admin:

1. **Setup → Products/Services → Servers → Add New Server**
   - Hostname `api.chainstack.com`, Type **Chainstack**, **Password** = your API key. Test Connection.
2. **Create a product**, Module = **Chainstack**, and either:
   - set **Default network** to a network slug (one product per network), or
   - attach a **Network** Configurable Option dropdown (one product, customer picks the network).

Full configuration, lifecycle behavior, and the network-dropdown helper are documented in
[`modules/servers/chainstack/README.md`](modules/servers/chainstack/README.md).

## Networks

The deployable network is resolved **live** against `GET /v2/deployment-options/` at provision
time, and the cloud is derived automatically — so every network Chainstack offers is supported
without code changes. List the available slugs:

```bash
CHAINSTACK_API_KEY=xxx php modules/servers/chainstack/scripts/list_networks.php
```

## Lifecycle

| Action | Effect |
|---|---|
| Create | Creates a project + node; endpoints appear in the client area (global nodes deploy synchronously). |
| Suspend | Deletes the node + project (stops usage/cost). |
| Unsuspend | Re-provisions — note the endpoint **URL changes** (new auth key). |
| Terminate | Deletes the node + project. |

## Tests

```bash
composer install
vendor/bin/phpunit
```

A manual end-to-end harness (creates and deletes one real node + project) is at
[`tests/e2e_harness.php`](tests/e2e_harness.php).

## Repository layout

```
modules/servers/chainstack/   the module (install this into WHMCS)
  scripts/                    operator helpers (list networks, sync dropdown)
tests/                        PHPUnit suite + manual e2e harness
```
