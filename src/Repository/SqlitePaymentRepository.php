<?php

declare(strict_types=1);

namespace App\Repository;

use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\Payment;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Domain\Payment\PaymentStatus;
use Payroad\Port\Repository\PaymentRepositoryInterface;

final class SqlitePaymentRepository implements PaymentRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new \PDO("sqlite:{$dbPath}", options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS payments (
                id                      TEXT PRIMARY KEY,
                amount_minor            INTEGER NOT NULL,
                currency_code           TEXT    NOT NULL,
                currency_precision      INTEGER NOT NULL,
                customer_id             TEXT    NOT NULL,
                status                  TEXT    NOT NULL DEFAULT 'pending',
                successful_attempt_id   TEXT,
                metadata                TEXT    NOT NULL DEFAULT '{}',
                expires_at              TEXT,
                refunded_amount_minor   INTEGER NOT NULL DEFAULT 0,
                version                 INTEGER NOT NULL DEFAULT 0,
                created_at              TEXT    NOT NULL
            )
        ");
    }

    public function nextId(): PaymentId
    {
        return PaymentId::generate();
    }

    public function save(Payment $payment): void
    {
        $row = [
            'id'                    => $payment->getId()->value,
            'amount_minor'          => $payment->getAmount()->getMinorAmount(),
            'currency_code'         => $payment->getAmount()->getCurrency()->code,
            'currency_precision'    => $payment->getAmount()->getCurrency()->precision,
            'customer_id'           => $payment->getCustomerId()->value,
            'status'                => $payment->getStatus()->value,
            'successful_attempt_id' => $payment->getSuccessfulAttemptId()?->value,
            'metadata'              => json_encode($payment->getMetadata()->toArray()),
            'expires_at'            => $payment->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'refunded_amount_minor' => $payment->getRefundedAmount()->getMinorAmount(),
            'version'               => $payment->getVersion(),
            'created_at'            => $payment->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $existing = $this->pdo
            ->query("SELECT version FROM payments WHERE id = '{$row['id']}'")
            ->fetch();

        if ($existing === false) {
            $cols = implode(', ', array_keys($row));
            $phs  = implode(', ', array_map(fn($k) => ":{$k}", array_keys($row)));
            $stmt = $this->pdo->prepare("INSERT INTO payments ({$cols}) VALUES ({$phs})");
        } else {
            if ((int) $existing['version'] !== $payment->getVersion()) {
                throw new \RuntimeException("Optimistic lock conflict for Payment {$row['id']}");
            }
            $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($row)));
            $stmt = $this->pdo->prepare("UPDATE payments SET {$sets} WHERE id = :id");
            $row['version']++;
        }

        $stmt->execute($row);
        $payment->incrementVersion();
    }

    public function findById(PaymentId $id): ?Payment
    {
        $row = $this->pdo
            ->query("SELECT * FROM payments WHERE id = '{$id->value}'")
            ->fetch();

        return $row !== false ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): Payment
    {
        $currency = new Currency($row['currency_code'], (int) $row['currency_precision']);

        $payment = new Payment(
            id:                  PaymentId::fromUuid($row['id']),
            amount:              Money::ofMinor((int) $row['amount_minor'], $currency),
            customerId:          new CustomerId($row['customer_id']),
            metadata:            PaymentMetadata::fromArray(json_decode($row['metadata'], true)),
            expiresAt:           $row['expires_at'] ? new \DateTimeImmutable($row['expires_at']) : null,
            status:              PaymentStatus::from($row['status']),
            successfulAttemptId: $row['successful_attempt_id']
                                     ? PaymentAttemptId::fromUuid($row['successful_attempt_id'])
                                     : null,
            createdAt:           new \DateTimeImmutable($row['created_at']),
            refundedAmount:      Money::ofMinor((int) $row['refunded_amount_minor'], $currency),
        );

        $payment->setVersion((int) $row['version']);

        return $payment;
    }
}
