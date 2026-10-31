# Changelog

## 1.0.0 - 2026-10-31

- Moyasar (hosted invoices) and Tap (hosted charges) drivers, plus `Checkout::extend()` for custom gateways.
- Idempotent `Checkout::start()` with a unique idempotency key.
- Webhook endpoint with Moyasar secret-token and Tap HMAC `hashstring` verification, duplicate-delivery protection, and 5xx on gateway errors so deliveries are retried.
- Reconciliation against the gateway API with amount and currency checks.
- Payment status state machine: paid and expired are final.
- `PaymentSucceeded` and `PaymentFailed` events, an optional queued mode, and the `safe-checkout:reconcile` command.
