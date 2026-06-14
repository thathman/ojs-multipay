# Changelog

Versioning: `A.B.C.D` — A new feature · B new sub-feature · C major fix · D minor fix.

## 1.1.0.2 - 2026-06-14

Minor fix.

### Fixed
- **Blank checkout / management pages on themes with an include-style header.**
  The checkout (`paymentSelection.tpl`) and the legacy management console
  (`manage.tpl`) used Smarty `{extends}` + `{block name="content"}`. On a theme
  whose `frontend/components/header.tpl` is include-style and does not declare a
  `content` block (e.g. the EFTBHS production theme), the extended content was
  discarded and the page rendered blank (header only, no body, no error). Both
  templates now use the `{include header}` … `{include footer}` pattern already
  used by the gateway plugins and the receipt template, so they render correctly
  on any theme.

## 1.1.0.1 - 2026-06-14

Hardening + audit fixes (minor fixes).

### Added
- **Per-gateway webhook URL** shown in settings, to paste into each gateway's
  dashboard. Without it, dispute alerts and asynchronous refund confirmation
  never fire — this was previously undiscoverable.

### Fixed
- **Inline credentials are now Test/Live aware**, selected by the Test Mode
  toggle. Previously a single key pair was used regardless of mode, so toggling
  Test Mode did nothing — risking live charges in test or failures in production.
- **Payment-manager list computes status in SQL**, so status filtering, totals
  and pagination stay consistent (status was filtered *after* pagination,
  producing wrong counts and short pages); the per-row N+1 queries are gone.
- **Numeric settings clamped on save:** amountTolerance [0–1000],
  fxMarkupPercent [-100–100], fxCacheTtl [60–604800] seconds.
- **Secrets are write-only** — never echoed back to the browser; a blank field
  keeps the stored value.
- **Refund derives money-moving fields server-side** from the payment id
  (gateway/reference/provider-tx-id/currency are no longer trusted from the
  client); partial amount is capped at the captured amount.
- **Empty "enabled gateways" is honoured** (no silent re-enable of every
  installed gateway).
- **Unconfigured gateways are hidden** at checkout instead of failing at
  initiation.
- `geoCountryCurrencyMap` is only stored when it is valid JSON.
- **Resend receipt emails the rendered receipt template** (was a plaintext
  summary).
- **Paystack adapter currency set now includes `XOF`**, matching the Paystack
  plugin's declared currencies.

### Changed
- Removed the dead `logLevel` setting (was stored but never read).
- The per-gateway currency matrix now shows only **enabled** gateways.
- Fresh-install schema uses `decimal(14,4)` for amount columns.

### Notes
- **Flutterwave API version.** MultiPay's Flutterwave adapter targets API **v3**
  (Bearer secret key). The standalone Flutterwave plugin uses **v4** (OAuth
  client id/secret; settings `v4TestClientId`/`v4TestClientSecret`/
  `v4LiveClientId`/`v4LiveClientSecret`). To orchestrate Flutterwave through
  MultiPay, enter a v3-compatible public/secret key inline in MultiPay's
  credentials — sibling-key auto-fill does not cross the v3/v4 boundary. A v4
  adapter for MultiPay is tracked as a follow-up.

## 1.1.0.0 - 2026-06-14

Feature release (new sub-features).

### Added
- **Redesigned checkout page** — segmented gateway selector + sticky order
  summary, styled to match the journal theme.
- **Currency / location detection** with an advisory "best gateway" notice
  (OJS profile country → Cloudflare `CF-IPCountry` → `Accept-Language`).
- **Display-only currency conversion.** Shows the payer an estimate in their
  local currency with a toggle and disclaimer; the gateway is always charged the
  journal currency/amount. Pluggable rate provider (Yahoo Finance default +
  configurable custom endpoint), signed markup, cached with TTL, hides
  gracefully on any fetch failure.
- **Per-gateway currency checkbox matrix** replacing the free-text allowed-
  currencies box and the routing JSON; allowed currencies and default routing
  are derived from the ticked selection.
- **Inline gateway credentials** managed from the MultiPay settings group;
  sibling gateway groups are merged in when MultiPay is active (the native
  payment-plugin selector and Manual Payment stay visible so staff can revert).
- **Multi-currency display formatting** (ISO-4217 minor units; intl with a
  symbol fallback) via a shared `Money` helper.
- **Payment manager** hooked into the core `/payments` page — filter / search /
  pagination, refunds, dispute record/resolve, and receipt view/resend with a
  themed printable receipt.
- **Dispute auto-flagging and asynchronous refund confirmation** via signed
  gateway webhooks; new `multipay_exchange_rates` and `multipay_disputes` tables.

### Fixed
- Single-gateway checkout CSRF bug (always render the POST form with a token).
- Refund replay / over-refund guard (idempotency key + captured-amount cap).

## 1.0.2.0 - 2026-06-11

Major fix.

### Fixed
- **PayPal adapter fatal.** `PaypalAdapter::validateWebhook()` was declared
  `($payload, $headers)` while `GatewayAdapterInterface` requires
  `(string $payload, array $headers): bool`. PHP raised a fatal incompatibility
  error whenever the PayPal adapter was loaded (i.e. any time a PayPal payment
  was routed), since `getAdapter()` loads the interface and the adapter together.
  Signature corrected. Caught by a live OJS-bootstrap integration test.

## 1.0.1.0 - 2026-06-11

Production-hardening release (major fixes).

### Security
- **Authorization on the management console (was CSRF-only).** `handleManage()`
  now requires a journal manager or site administrator before any side effect.
  Previously refunds and settlement reports were reachable by any authenticated
  user who could obtain a CSRF token.

### Fixed
- **Idempotency no longer swallows database errors.** `IdempotencyService::claim()`
  treats only a genuine unique-key collision as "already processed"; any other
  DB error is logged and rethrown, so a transient failure can no longer be
  silently reported as a duplicate (which would skip fulfilment and ack the
  webhook with HTTP 200, losing the payment). The gateway now retries instead.
- **Paystack amount conversion is minor-unit aware** (ISO-4217 exponent): x100
  for two-decimal currencies, x1 for zero-decimal (JPY/KRW…), x1000 for
  three-decimal (KWD/BHD…). Behaviour is unchanged for the currently supported
  set (all two-decimal) but is now correct if the set widens. Verify/normalize
  round-trip back to major units.
- **PayPal currency handling.** `supportsCurrency()` now returns true only for
  PayPal's accepted currency set (was: any non-empty string), preventing the
  router from sending PayPal currencies it cannot process; amounts for
  zero-decimal PayPal currencies (HUF/JPY/TWD) are sent without decimals.

### Added
- Regression test `tests/PaystackAmountTest.php` for minor-unit conversion.
- `LICENSE` (GPL-3.0), `.gitignore`, `<compatibility>` block in `version.xml`.

### Notes
- PayPal remains **experimental** (Omnipay PayPal_Rest / v1 API; async webhooks
  rejected, refunds unsupported). Migration to PayPal Orders v2 is tracked
  separately, as is the broader live currency-conversion engine.

## 1.0.0.0 - 2026-03-05
- Added orchestrator paymethod plugin for multi-gateway routing.
- Added Paystack and Flutterwave adapters with initiate, verify, webhook validation, and refund operations.
- Added routing, metadata, webhook log, idempotency, reconciliation, and recurring services.
- Added admin management views for transactions, webhooks, routing, refunds, reconciliation, and recurring diagnostics.
- Added install migration for transaction, webhook, idempotency, refunds, reconciliation, recurring, and settlement tables.

