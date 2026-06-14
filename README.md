# MultiPay Payment Plugin for OJS

MultiPay is a multi-gateway payment **orchestration** plugin for Open Journal
Systems (OJS). It accepts the journal's existing fee in its configured currency
and routes each payment to the most appropriate gateway (Paystack, Flutterwave,
PayPal\*, Manual, and any other installed paymethod plugin), with a redesigned
checkout, location-aware gateway suggestions, display-only currency conversion,
and a full payment-management console hooked into the core `/payments` page.

- **Compatibility:** OJS 3.5.0.0 – 3.5.0.3
- **Licence:** GNU GPL v3 (see `LICENSE`)
- **Type:** `plugins.paymethod`
- **Version:** 1.1.0.2

## Maintainer
- Name: Hendrix Nwaokolo
- Organisation: Airix Media
- Website: https://ojs.airixmedia.com

## How charging works (important)
MultiPay **always charges the journal's currency and amount**. It does **not**
convert the charged amount. The optional currency conversion is **display-only**:
the payer is shown an estimate in their local currency purely for information,
with a toggle back to the journal currency and a disclaimer. The gateway (and the
payer's bank) performs any real conversion at its own rate.

## Features

### Checkout
- Redesigned checkout page: a segmented gateway selector and a sticky order
  summary, styled to match the journal theme.
- Only gateways that can **settle the journal currency** are offered; a gateway
  that can't (or isn't configured) is hidden rather than failing mid-payment.
- Advisory **"best gateway" notice** based on the payer's detected location
  (OJS profile country → Cloudflare `CF-IPCountry` → `Accept-Language`).
- **Display-only currency estimate** in the payer's local currency with a
  show-in/show-back toggle and a clear disclaimer. Pluggable rate provider
  (Yahoo Finance by default, or a configurable custom endpoint), an optional
  signed markup %, and a cache TTL. Hides gracefully if a rate can't be fetched.

### Configuration
- **Per-gateway currency matrix.** For each enabled, adapter-backed gateway you
  tick the currencies it should accept (only currencies the gateway can settle
  are listed). The union becomes the allowed-currency set and a default
  currency→gateway route is derived automatically — no free-text currency box or
  routing JSON to hand-maintain.
- **Inline credentials, Test + Live.** Manage every gateway's keys from the one
  MultiPay group. The **Test Mode** toggle selects whether the Test or Live keys
  are used. Secret fields are **write-only** (shown blank; enter a value only to
  change it). Blank fields fall back to that gateway plugin's own configured keys.
- **Webhook URL per gateway** is shown for you to paste into the gateway
  dashboard — required for dispute alerts and asynchronous refund confirmation.
- **Fallback gateway** chooser, amount-tolerance, and FX/geo options, each with
  sensible defaults.
- When MultiPay is the active payment plugin, the sibling gateway settings groups
  (Paystack / Flutterwave / PayPal) are merged into MultiPay to avoid duplicate
  configuration. The native payment-plugin selector and Manual Payment stay
  visible, so you can revert to a single gateway at any time.

### Payment management (`/payments`)
The plugin's companion **paymethodSupport** generic plugin adds a *Payment
manager* tab to the core Payments page with:
- Filter (gateway / status / type / date range), text search, and pagination.
- Per-payment **refund** (asynchronous: request → pending → webhook-confirmed,
  idempotent, capped at the captured amount).
- **Dispute** record/resolve with staff notes and history.
- **Receipt** view and **resend** using a themed printable receipt template.

### Integrity & security
- Gateway callbacks and webhooks are **signature-verified**; amount and currency
  are re-validated against the queued payment before fulfilment.
- Webhook/callback deliveries are **idempotent**; a transient DB error is a
  retryable failure rather than a silently dropped payment.
- **Disputes and refunds are webhook-driven** and pass the same signature gate.
- Refund actions derive the gateway/reference/provider-tx-id/currency
  **server-side** from the payment id; nothing money-moving is trusted from the
  client.
- The legacy management console (transactions, webhooks, routing preview,
  reconciliation, recurring) requires a **journal manager or site administrator**.

\* **PayPal is experimental** — Omnipay PayPal_Rest (v1) driver; asynchronous
webhooks are rejected and refunds are unsupported. Keep it disabled in production
until it is migrated to PayPal Orders v2.

## Gateway integration notes
MultiPay auto-detects every installed paymethod plugin. For the gateways it has
native adapters for, it reads the sibling plugin's keys as a fallback:

| Gateway | API | Credential settings MultiPay reads (fallback) | Supported currencies |
|---|---|---|---|
| **Paystack** | v1 (Bearer secret) | `testPublicKey`, `testSecretKey`, `livePublicKey`, `liveSecretKey` | NGN, USD, GHS, ZAR, KES, XOF |
| **Flutterwave** | adapter targets **v3** (Bearer secret) | `webhookHash` (webhook). See note below. | NGN, USD, EUR, GBP, GHS, KES, ZAR, XAF, XOF, UGX, RWF, TZS, EGP, MWK |
| **PayPal** (experimental) | Omnipay PayPal_Rest v1 | `clientId`, `secret`, `testMode` | major PayPal currencies |
| **Manual / others** | delegated | n/a (handled by the plugin's own form) | gateway-defined |

> **Flutterwave v3/v4 note.** MultiPay's Flutterwave adapter currently targets
> Flutterwave **API v3** (Bearer secret key). The standalone Flutterwave plugin
> has moved to **v4** (OAuth client id/secret; settings `v4TestClientId`,
> `v4TestClientSecret`, `v4LiveClientId`, `v4LiveClientSecret`). Because the key
> *types* differ, MultiPay cannot auto-fill Flutterwave keys from the v4 plugin.
> To orchestrate Flutterwave through MultiPay today, enter a v3-compatible
> public/secret key inline in MultiPay's credentials. A v4 adapter is a tracked
> follow-up.

## Installation
1. Upload via the Plugin Gallery or extract to `plugins/paymethod/multipay`.
2. Enable in **Settings → Distribution → Payments** and choose **MultiPay** as
   the payment plugin.
3. Enable gateways, enter their Test/Live keys, tick supported currencies, and
   paste each gateway's webhook URL into its dashboard.
4. Install the companion **paymethodSupport** plugin to enable the `/payments`
   manager tab and receipts.

## Tests
```bash
php plugins/paymethod/multipay/tests/run.php
```

## Development
- New gateways implement `classes/GatewayAdapterInterface` (initiate, verify,
  webhook validate/normalize, refund, `supportsCurrency`, `getSupportedCurrencies`).
- Shared `classes/Money.php` is the single source of truth for ISO-4217 minor
  units and display formatting.
- Install migration creates the transaction, webhook-log, idempotency, refunds,
  reconciliation, recurring, settlement, exchange-rate, and disputes tables.

## Releasing / packaging
Following the [PKP plugin release guide](https://docs.pkp.sfu.ca/dev/plugin-guide/en/release):
1. Bump `<release>` and `<date>` in `version.xml`; update `CHANGELOG.md`.
2. Tag the commit (e.g. `git tag v1.1.0.2 && git push --tags`).
3. Build the archive with the plugin folder at the top level:
   ```
   tar -czf multipay-1.1.0.2.tar.gz --exclude-vcs --exclude='*.tar.gz' multipay/
   ```
4. Attach the archive to the GitHub release for the tag.
