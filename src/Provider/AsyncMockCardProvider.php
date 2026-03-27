<?php

declare(strict_types=1);

namespace App\Provider;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Card\CardPaymentAttempt;
use Payroad\Domain\PaymentFlow\Card\CardRefund;
use Payroad\Domain\Refund\RefundId;
use Payroad\Port\Provider\Card\CardAttemptContext;
use Payroad\Port\Provider\Card\CardProviderInterface;
use Payroad\Port\Provider\Card\CardRefundContext;
use Payroad\Provider\InternalCard\Data\InternalCardAttemptData;

/**
 * Async mock provider for webhook demo purposes.
 *
 * Unlike InternalCardProvider (mode: charge), this provider stops at PROCESSING
 * so that the developer can trigger completion manually via POST /dev/webhook/{paymentId}.
 *
 * This illustrates the async flow used by real processors:
 *   PENDING → PROCESSING  (on /pay)
 *   PROCESSING → SUCCEEDED  (on webhook arrival)
 */
final class AsyncMockCardProvider implements CardProviderInterface
{
    public function __construct(private readonly string $providerName) {}

    public function supports(string $providerName): bool
    {
        return $providerName === $this->providerName;
    }

    public function initiateCardAttempt(
        PaymentAttemptId   $id,
        PaymentId          $paymentId,
        string             $providerName,
        Money              $amount,
        CardAttemptContext $context,
    ): CardPaymentAttempt {
        $attempt = CardPaymentAttempt::create($id, $paymentId, $providerName, $amount, new InternalCardAttemptData());
        $attempt->setProviderReference('mock_async_' . $id->value);
        $attempt->applyTransition(AttemptStatus::PROCESSING, 'mock_processing');

        return $attempt;
    }

    public function initiateRefund(
        RefundId         $id,
        PaymentId        $paymentId,
        PaymentAttemptId $originalAttemptId,
        string           $providerName,
        Money            $amount,
        string           $originalProviderReference,
        CardRefundContext $context,
    ): CardRefund {
        throw new \LogicException('Refunds are not supported by the async mock provider.');
    }

    public function parseIncomingWebhook(array $payload, array $headers): null
    {
        return null;
    }
}
