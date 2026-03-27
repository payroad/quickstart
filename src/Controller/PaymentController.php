<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\KnownCurrencies;
use Payroad\Application\UseCase\Card\InitiateCardAttemptCommand;
use Payroad\Application\UseCase\Card\InitiateCardAttemptUseCase;
use Payroad\Application\UseCase\Payment\CreatePaymentCommand;
use Payroad\Application\UseCase\Payment\CreatePaymentUseCase;
use Payroad\Application\UseCase\Webhook\HandleWebhookCommand;
use Payroad\Application\UseCase\Webhook\HandleWebhookUseCase;
use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Port\Repository\PaymentAttemptRepositoryInterface;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Domain\PaymentFlow\Card\CardAttemptData;
use Payroad\Port\Provider\Card\CardAttemptContext;
use Payroad\Port\Repository\PaymentRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly CreatePaymentUseCase              $createPayment,
        private readonly InitiateCardAttemptUseCase        $initiateCardAttempt,
        private readonly HandleWebhookUseCase              $handleWebhook,
        private readonly PaymentRepositoryInterface        $payments,
        private readonly PaymentAttemptRepositoryInterface $attempts,
    ) {}

    // ── Checkout form ─────────────────────────────────────────────────────────

    #[Route('/', methods: ['GET'])]
    public function checkout(Request $request): Response
    {
        return $this->render('checkout.html.twig', [
            'stripe_publishable_key' => $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? null,
        ]);
    }

    // ── Create payment + initiate attempt ─────────────────────────────────────

    /**
     * POST /pay
     *
     * Body: { "amount": "19.99", "currency": "USD", "provider": "mock_card" }
     *
     * Response:
     *   { "paymentId": "...", "status": "processing", "clientToken": "..." }
     *
     * clientToken is the Stripe client_secret when using the stripe provider.
     * It is null for the mock_card provider (payment resolves synchronously).
     */
    #[Route('/pay', methods: ['POST'])]
    public function pay(Request $request): JsonResponse
    {
        $body     = $request->toArray();
        $currency = KnownCurrencies::get($body['currency'] ?? 'USD');
        $amount   = \Payroad\Domain\Money\Money::ofDecimal($body['amount'] ?? '10.00', $currency);
        $provider = $body['provider'] ?? 'mock_card';

        $payment = $this->createPayment->execute(new CreatePaymentCommand(
            amount:     $amount,
            customerId: new CustomerId($body['customerId'] ?? 'guest'),
            metadata:   PaymentMetadata::fromArray($body['metadata'] ?? []),
        ));

        $attempt = $this->initiateCardAttempt->execute(new InitiateCardAttemptCommand(
            paymentId:    $payment->getId(),
            providerName: $provider,
            context:      new CardAttemptContext(
                customerIp:       $request->getClientIp() ?? '0.0.0.0',
                browserUserAgent: $request->headers->get('User-Agent', ''),
            ),
        ));

        /** @var CardAttemptData $data */
        $data = $attempt->getData();

        return $this->json([
            'paymentId'   => $payment->getId()->value,
            'status'      => $attempt->getStatus()->value,
            'clientToken' => $data->getClientToken(),
        ]);
    }

    // ── Payment status ────────────────────────────────────────────────────────

    #[Route('/pay/{id}/status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $payment = $this->payments->findById(PaymentId::fromUuid($id));

        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], 404);
        }

        return $this->json([
            'paymentId' => $payment->getId()->value,
            'status'    => $payment->getStatus()->value,
            'amountMinor' => $payment->getAmount()->getMinorAmount(),
            'currency'    => $payment->getAmount()->getCurrency()->code,
        ]);
    }

    // ── Dev: simulate webhook ─────────────────────────────────────────────────

    /**
     * POST /dev/webhook/{id}
     *
     * Simulates an async webhook arriving for a payment — useful for demonstrating
     * the mock_card_async provider without a real webhook source.
     *
     * Finds the latest non-terminal attempt for the payment and transitions it
     * to SUCCEEDED via HandleWebhookUseCase, exactly as a real provider webhook would.
     */
    #[Route('/dev/webhook/{id}', methods: ['POST'])]
    public function devWebhook(string $id): JsonResponse
    {
        $payment = $this->payments->findById(PaymentId::fromUuid($id));
        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], 404);
        }

        $attempts = $this->attempts->findByPaymentId(PaymentId::fromUuid($id));
        $attempt  = null;
        foreach (array_reverse($attempts) as $a) {
            if (!$a->getStatus()->isTerminal()) {
                $attempt = $a;
                break;
            }
        }

        if ($attempt === null) {
            return $this->json(['error' => 'No non-terminal attempt found — payment may already be complete'], 422);
        }

        $this->handleWebhook->execute(new HandleWebhookCommand(
            providerName: $attempt->getProviderName(),
            result:       new WebhookResult(
                providerReference: $attempt->getProviderReference(),
                newStatus:         AttemptStatus::SUCCEEDED,
                providerStatus:    'mock_webhook_succeeded',
            ),
        ));

        return $this->json(['triggered' => true, 'attemptId' => $attempt->getId()->value]);
    }

    // ── Stripe webhook ────────────────────────────────────────────────────────

    /**
     * POST /webhook/stripe
     *
     * Stripe sends events here after a payment is confirmed.
     * Set this URL in your Stripe dashboard or via `stripe listen --forward-to`.
     */
    #[Route('/webhook/stripe', methods: ['POST'])]
    public function stripeWebhook(Request $request): Response
    {
        $this->handleWebhook->execute(new HandleWebhookCommand(
            providerName: 'stripe',
            payload:      $request->toArray(),
            headers:      array_merge(
                $request->headers->all(),
                ['raw-body' => [$request->getContent()]],
            ),
        ));

        return new Response('', 200);
    }
}
