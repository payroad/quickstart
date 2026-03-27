<?php

declare(strict_types=1);

namespace App\Repository;

use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Refund\Refund;
use Payroad\Domain\Refund\RefundId;
use Payroad\Port\Repository\RefundRepositoryInterface;

/**
 * No-op refund repository.
 * Refunds are not covered in the quickstart — use payroad-symfony-demo for the full flow.
 */
final class NullRefundRepository implements RefundRepositoryInterface
{
    public function nextId(): RefundId
    {
        return RefundId::generate();
    }

    public function save(Refund $refund): void {}

    public function findById(RefundId $id): ?Refund
    {
        return null;
    }

    public function findByProviderReference(string $providerName, string $reference): ?Refund
    {
        return null;
    }

    public function findByPaymentId(PaymentId $paymentId): array
    {
        return [];
    }
}
