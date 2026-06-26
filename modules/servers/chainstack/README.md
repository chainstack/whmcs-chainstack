# Chainstack WHMCS Provisioning Module

Provisions Chainstack RPC/WSS endpoints (nodes) for WHMCS customers. **One WHMCS service = one
Chainstack node**, deployed into a per-service project inside a single operator-owned Chainstack
organization. Authenticates with one admin API key.

## Requirements

- WHMCS 8.x (tested with PHP 8.3). PHP extensions: `curl`, `json`, `openssl`.
- A Chainstack organization on a plan that permits the Platform API and node creation.
- An admin **API key** (Chainstack console → Settings → API keys).

## Install

1. Copy this directory to `<whmcs>/modules/servers/chainstack/`.
2. Ensure files are owned by the web user and readable (dirs 755, files 644).

## Configure

### 1. Add the server
Setup → Products/Services → **Servers** → Add New Server:
- **Name:** Chainstack
- **Hostname:** `api.chainstack.com`
- **Type:** Chainstack
- **Password:** *your Chainstack admin API key* (stored encrypted by WHMCS)
- Leave **"Check to use SSL Mode for Connections"** enabled — the module uses it to select the
  scheme, and the API is https-only, so the key is never sent over plain HTTP.
- Save → **Test Connection** (calls `GET /v1/organization/`).

### 2. Create one product per network
Each product maps to a fixed network. For each chain you want to sell:
- Create a product (e.g. "Ethereum Mainnet Node").
- Module Settings → Module Name = **Chainstack**.
- **Default network** = the network slug (e.g. `ethereum-mainnet`).
  Run `scripts/list_networks.php` for the full list of valid slugs:
  ```
  CHAINSTACK_API_KEY=xxx php scripts/list_networks.php
  ```

### 3. (Alternative) One product, customer picks the network from a dropdown
The same module also supports a single "Chainstack Node" product where the customer selects the
network at order time. Resolution precedence: **Configurable Option `Network` › product `Default
network`**, so both styles coexist.

Run the sync helper to create/refresh the `Network` Configurable Option group (dropdown of all
network slugs, priced free) directly from the live API. **Run it as your WHMCS web/PHP user**
(not root) so WHMCS bootstrap doesn't create root-owned cache files:

```bash
# from the WHMCS root, using the PHP binary your WHMCS runs on:
php modules/servers/chainstack/scripts/setup_network_option.php
```
- API key is auto-detected from the configured Chainstack server (or pass `CHAINSTACK_API_KEY`).
- Pass `PRODUCT_ID=<id>` to auto-link the group to a product, or attach it manually via
  Products/Services → edit → Configurable Options.
- Idempotent: re-run anytime to pick up new networks (existing values and any prices you set are
  left untouched). Networks removed upstream are reported, not deleted.

The Configurable Option **must be named `Network`** (the module reads `configoptions['Network']`).
Each value is written as `slug|Friendly Name` (e.g. `ethereum-sepolia-testnet|Ethereum Sepolia
Testnet`) — WHMCS shows the friendly name to customers and passes the **slug** to the module. The
helper generates the friendly names automatically (with nicer casing for BNB Smart Chain, PoS,
zkEVM, opBNB, etc.).

## Lifecycle behavior

| WHMCS action | Effect |
|---|---|
| **Create** | Creates a project + one node; stores their IDs on the service. Endpoints appear in the client area (global nodes deploy synchronously). |
| **Suspend** | **Deletes the node + project** (stops all usage/cost). |
| **Unsuspend** | **Re-provisions** a fresh project + node. ⚠️ The endpoint **URL changes** (new auth key) — the previous URL does not return. |
| **Terminate** | Deletes the node + project. |
| **Client buttons** | `Refresh Status`. |
| **Admin buttons** | `Create Endpoint` (recovery), `Refresh Status`. |

**Important:** because suspend tears down and unsuspend recreates, a suspend/unsuspend cycle gives
the customer a **new endpoint URL**. Communicate this to customers if you rely on automatic
suspension (e.g. overdue invoices).

## Errors

API errors are surfaced with friendly text where mapped — e.g. hitting the org's node limit shows
*"Your Chainstack account has reached its node limit. Please contact your administrator…"*. All
calls are logged via WHMCS Module Log (Utilities → Logs → Module Log); the API key is redacted.

## Blockchain icons

Per-protocol icons come from Chainstack's CDN (`https://static.chainstack.dev/<protocol>.svg`,
the same source the console uses). They appear:
- next to each endpoint inside the service's client-area panel, and
- as the product-details **header icon** (swapped in via a `ClientAreaFooterOutput` hook — no theme
  files are modified). Unknown protocols fall back to a small inline generic SVG.

The header swap reads the protocol stored at provision time, so it shows only for services
provisioned by the current module version.

## Reliability & pricing

- **Re-run safe.** Module commands are idempotent: WHMCS re-running a failed `Create` will not
  create duplicate projects/nodes, and a node-create failure rolls back a project created in the
  same call. (No custom HTTP retry — WHMCS's command re-run is the retry mechanism.)
- **Synchronous deploys.** Scope is global/elastic nodes, which return `status=running` with
  endpoints immediately; the client area fetches status live. There is no background sync cron.
- **Pricing.** The sync helper creates network options priced **free (0.00)**. The operator
  (WHMCS account owner) sets whatever prices they want per product / configurable option.

## Files

```
chainstack.php                WHMCS module functions (incl. AdminServicesTabFields)
hooks.php                     ClientAreaFooterOutput: product-details blockchain icon swap
lib/ChainstackClient.php      HTTP client (Bearer auth, typed errors)
lib/Provisioner.php           lifecycle orchestration + live network resolution
lib/Helpers.php               server config, per-service state, naming, friendly errors
templates/clientarea.tpl      endpoint display
scripts/list_networks.php     list deployable network slugs (run with CHAINSTACK_API_KEY)
scripts/setup_network_option.php  create/sync the "Network" Configurable Option dropdown
```

State is stored on the service via `serviceProperties`: `chainstack_project_id`,
`chainstack_node_ids`, `chainstack_status`, `chainstack_protocol`.

## Tests

PHPUnit suite under `tests/` (run from the package root):
```
composer install
vendor/bin/phpunit
```
