# Changelog

Versioning: `A.B.C.D` — A new feature · B new sub-feature · C major fix · D minor fix.

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

