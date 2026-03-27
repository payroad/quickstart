# Payroad Quickstart

Get a working payment page in **under 5 minutes** — no database server required.

Includes a mock card provider that works out of the box, and optional Stripe integration for real payments.

---

## Quick start (Docker)

```bash
git clone https://github.com/payroad/quickstart payroad-quickstart
cd payroad-quickstart
docker build -t payroad-quickstart .
docker run -p 8000:8000 payroad-quickstart
```

Open [http://localhost:8000](http://localhost:8000) — you'll see a checkout page.

Click **Pay** — the mock provider resolves the payment instantly with no API keys.

---

## Alternative: native PHP

Requirements: PHP 8.2+ with `bcmath`, `pdo_sqlite` extensions, Composer.

```bash
composer install
php -S localhost:8000 -t public
```

---

## Enable Stripe

Pass your keys as environment variables:

```bash
docker run -p 8000:8000 \
  -e STRIPE_PUBLISHABLE_KEY=pk_test_... \
  -e STRIPE_SECRET_KEY=sk_test_... \
  -e STRIPE_WEBHOOK_SECRET=whsec_... \
  payroad-quickstart
```

Forward webhooks locally (requires [Stripe CLI](https://stripe.com/docs/stripe-cli)):

```bash
stripe listen --forward-to localhost:8000/webhook/stripe
```

Refresh the page — a **Stripe** option appears in the provider selector.

---

## How it works

```
Browser          PaymentController        payroad-core          Provider
  │                     │                      │                    │
  │── POST /pay ────────▶                      │                    │
  │               CreatePaymentUseCase         │                    │
  │               InitiateCardAttemptUseCase ──▶── initiateCardAttempt()
  │                     │                      │         │          │
  │                     │◀─────────────────────│─────────┘          │
  │◀── {paymentId, ─────┘                      │
  │     clientToken}
  │
  │  [mock: payment already succeeded]
  │  [stripe: confirm via Stripe.js, then webhook arrives]
```

The domain core (`payroad/payroad-core`) knows nothing about Stripe, HTTP, or databases.
Providers implement a clean interface. Your application wires them together.

---

## API endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/` | Checkout form |
| `POST` | `/pay` | Create payment + initiate attempt |
| `GET` | `/pay/{id}/status` | Poll payment status |
| `POST` | `/webhook/stripe` | Stripe webhook receiver |

`POST /pay` body:

```json
{ "amount": "19.99", "currency": "USD", "provider": "mock_card" }
```

Response:

```json
{ "paymentId": "...", "status": "succeeded", "clientToken": null }
```

`clientToken` is the Stripe `client_secret` when using `stripe`. It is `null` for `mock_card`.

---

## Project structure

```
src/
  Controller/
    PaymentController.php       # GET /, POST /pay, GET /pay/{id}/status, POST /webhook/stripe
  Repository/
    SqlitePaymentRepository.php
    SqliteAttemptRepository.php
  Infrastructure/
    KnownCurrencies.php         # maps currency codes to precision
    NullEventDispatcher.php     # discards domain events (replace with Messenger in production)
config/
  packages/payroad.yaml         # provider configuration
templates/
  checkout.html.twig            # minimal checkout form + Stripe.js
var/
  payroad.sqlite                # auto-created on first request
```

---

## Local development (with editable core packages)

If you're modifying `payroad-core` or providers locally, use Docker Compose — it mounts sibling repos directly:

```bash
# expected directory layout:
# payroad/
#   payroad-quickstart/
#   payroad-core/
#   stripe-provider/
#   internal-card-provider/
#   symfony-bridge/

docker compose up --build
```

App runs on [http://localhost:8001](http://localhost:8001).

---

## Key concepts

**Payment and PaymentAttempt are separate aggregates.**
`Payment` is a thin business document. `PaymentAttempt` drives actual money movement through a provider.
Multiple attempts can exist per payment (retries after failure).

**Providers implement a port interface.**
`CardProviderInterface` defines the contract. Stripe, Braintree, or your own processor implement it.
Switching providers requires zero changes to domain or use-case code.

**Domain events are provider-agnostic.**
The domain emits `PaymentSucceeded`, not `StripePaymentSucceeded`.
`NullEventDispatcher` discards them here — replace with Symfony Messenger to react to them.

---

## Going further

| Need | Where to look |
|------|--------------|
| All payment flows (crypto, P2P, cash) | [payroad-symfony-demo](https://github.com/payroad/payroad-symfony-demo) |
| Refunds, saved cards, auth/capture | [payroad-core use cases](https://github.com/payroad/payroad-core) |
| Domain model reference | [payroad/payroad-core README](https://github.com/payroad/payroad-core) |

---

## License

MIT
