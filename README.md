# MultiPay Payment Plugin for OJS

MultiPay is a multi-gateway payment orchestration plugin for Open Journal
Systems (OJS). It accepts the journal's existing fee in its configured currency
and **routes** each payment to the most appropriate gateway (Paystack,
Flutterwave, PayPal, …) based on currency and a configurable routing map.

> **Scope note:** MultiPay routes payments by currency; it does **not** convert
> between currencies. Live currency conversion (rate lookup, user currency
> selection, converted-amount storage) is a separate, planned capability and is
> not part of this release.

- **Compatibility:** OJS 3.5.0.0 – 3.5.0.3
- **Licence:** GNU GPL v3 (see `LICENSE`)
- **Type:** `plugins.paymethod`

## Maintainer
- Name: Hendrix Nwaokolo
- Organisation: Airix Media
- Website: https://ojs.airixmedia.com

## License
This plugin is distributed under the **GNU GPL v3** (the same license already used in OJS plugin headers).  
GPLv3 requires preserving copyright/license notices and marking modifications, and it allows commercial distribution, sponsorship/donations, and paid/pro service tiers.

## Features
- Supports multiple installed payment plugins (Paystack, Flutterwave, PayPal*, Manual, and future compatible gateways).
- Deterministic currency-based routing with fallback gateway.
- Unified gateway orchestration settings with gateway tab selector.
- Unified payment selection page for users.
- Strict callback verification and idempotent webhook processing.
- Refunds, reconciliation, and recurring diagnostics views (manager/admin only).

\* **PayPal is experimental** — it uses the Omnipay PayPal_Rest (v1) driver;
asynchronous webhooks are rejected and refunds are unsupported. Keep it disabled
in production until it is migrated to PayPal Orders v2.

## Security
- The management console (transactions, refunds, settlement reports, recurring
  profiles) requires a **journal manager or site administrator**; other users
  receive a 404.
- Gateway callbacks and webhooks are signature-verified and amount/currency are
  re-validated against the queued payment before fulfilment.
- Webhook deliveries are de-duplicated idempotently; a transient database error
  causes a retryable failure rather than a silently dropped payment.

## Installation
1. Upload the plugin tarball via the Plugin Gallery or extract it to `plugins/paymethod/multipay`.
2. Enable the plugin in Website Settings -> Plugins -> Payment Methods.
3. Configure the settings (API keys, enabled gateways).

## Configuration
- **Enabled Gateways**: Select which gateways to offer.
- **API Keys**: Enter Public/Secret keys for each enabled gateway.
- **Allowed Currencies**: (Optional) Restrict currencies.
- **Currency Routing JSON**: Configure default gateway per currency.
- **Fallback Gateway**: Define fallback when currency map has no match.
- **Amount Tolerance**: Configure amount verification threshold.

## Manage Views
- Transactions
- Webhooks
- Routing Preview
- Refunds
- Reconciliation
- Recurring

## Tests
Run:
```bash
php plugins/paymethod/multipay/tests/run.php
```

## Development
- The plugin uses a `GatewayAdapterInterface` to allow easy addition of new gateways.
- Database tables for transactions, webhook logs, idempotency, refunds, reconciliation jobs, recurring profiles, and settlement reports are created on install.

## Releasing / packaging (for distribution)

Following the [PKP plugin release guide](https://docs.pkp.sfu.ca/dev/plugin-guide/en/release):

1. Bump `<release>` and `<date>` in `version.xml`, update `CHANGELOG.md`.
2. Tag the commit (e.g. `git tag v1.0.1.0 && git push --tags`).
3. Build the archive with the plugin folder at the top level:
   ```
   tar -czf multipay-1.0.1.0.tar.gz --exclude-vcs --exclude='*.tar.gz' multipay/
   ```
4. Attach the archive to the GitHub release for the tag.
5. (Optional) Submit to the OJS Plugin Gallery per the PKP guide.

The archive must contain a single top-level `multipay/` directory so OJS's
**Upload A New Plugin** installer places it correctly.
