# Payroad Quickstart

Get a working payment page in **under 5 minutes** — no database server required.

Includes a mock card provider that works out of the box, and optional Stripe integration for real payments.

---

## Requirements

- PHP 8.2+ with `pdo_sqlite` extension
- Composer

---

## Setup

```bash
git clone https://github.com/payroad/quickstart payroad-quickstart
cd payroad-quickstart

composer install

cp .env.example .env
# edit .env and set APP_SECRET to any random string

php -S localhost:8000 -t public
```

Open [http://localhost:8000](http://localhost:8000) — you'll see a checkout page.

Click **Pay** — the mock provider resolves the payment instantly with no API keys.

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

## Enable Stripe

1. Add your keys to `.env`:

```env
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

2. Forward webhooks locally:

```bash
stripe listen --forward-to localhost:8000/webhook/stripe
```

3. Refresh the page — a **Stripe** option appears in the provider selector.

---

## Project structure

```
src/
  Controller/
    PaymentController.php      # GET /, POST /pay, GET /pay/{id}/status, POST /webhook/stripe
  Repository/
    SqlitePaymentRepository.php
    SqliteAttemptRepository.php
  Infrastructure/
    KnownCurrencies.php        # maps currency codes to precision
    NullEventDispatcher.php    # discards domain events (replace with Messenger in production)
config/
  packages/payroad.yaml        # provider configuration
templates/
  checkout.html.twig           # minimal checkout form + Stripe.js
var/
  payroad.sqlite               # auto-created on first request
```

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
| Braintree, NOWPayments, CoinGate | Provider packages in the [payroad org](https://github.com/payroad) |
| Domain model reference | [payroad/payroad-core README](https://github.com/payroad/payroad-core) |

---

## License

MIT
