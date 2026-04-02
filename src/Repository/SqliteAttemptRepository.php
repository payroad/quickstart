<?php

declare(strict_types=1);

namespace App\Repository;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttempt;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Channel\Card\CardPaymentAttempt;
use Payroad\Port\Repository\PaymentAttemptRepositoryInterface;

final class SqliteAttemptRepository implements PaymentAttemptRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new \PDO("sqlite:{$dbPath}", options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_attempts (
                id                  TEXT PRIMARY KEY,
                payment_id          TEXT    NOT NULL,
                provider_name       TEXT    NOT NULL,
                method_type         TEXT    NOT NULL DEFAULT 'card',
                amount_minor        INTEGER NOT NULL,
                currency_code       TEXT    NOT NULL,
                currency_precision  INTEGER NOT NULL,
                status              TEXT    NOT NULL DEFAULT 'pending',
                provider_status     TEXT    NOT NULL DEFAULT 'pending',
                provider_reference  TEXT,
                data                TEXT    NOT NULL DEFAULT '{}',
                version             INTEGER NOT NULL DEFAULT 0,
                created_at          TEXT    NOT NULL
            )
        ");
    }

    public function nextId(): PaymentAttemptId
    {
        return PaymentAttemptId::generate();
    }

    public function save(PaymentAttempt $attempt): void
    {
        $dataPayload = json_encode([
            '_class' => $attempt->getData()::class,
            ...$attempt->getData()->toArray(),
        ]);

        $row = [
            'id'                 => $attempt->getId()->value,
            'payment_id'         => $attempt->getPaymentId()->value,
            'provider_name'      => $attempt->getProviderName(),
            'method_type'        => $attempt->getMethodType()->value,
            'amount_minor'       => $attempt->getAmount()->getMinorAmount(),
            'currency_code'      => $attempt->getAmount()->getCurrency()->code,
            'currency_precision' => $attempt->getAmount()->getCurrency()->precision,
            'status'             => $attempt->getStatus()->value,
            'provider_status'    => $attempt->getProviderStatus(),
            'provider_reference' => $attempt->getProviderReference(),
            'data'               => $dataPayload,
            'version'            => $attempt->getVersion(),
            'created_at'         => $attempt->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $existing = $this->pdo
            ->query("SELECT version FROM payment_attempts WHERE id = '{$row['id']}'")
            ->fetch();

        if ($existing === false) {
            $cols = implode(', ', array_keys($row));
            $phs  = implode(', ', array_map(fn($k) => ":{$k}", array_keys($row)));
            $stmt = $this->pdo->prepare("INSERT INTO payment_attempts ({$cols}) VALUES ({$phs})");
        } else {
            if ((int) $existing['version'] !== $attempt->getVersion()) {
                throw new \RuntimeException("Optimistic lock conflict for Attempt {$row['id']}");
            }
            $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($row)));
            $stmt = $this->pdo->prepare("UPDATE payment_attempts SET {$sets} WHERE id = :id");
            $row['version']++;
        }

        $stmt->execute($row);
        $attempt->incrementVersion();
    }

    public function findById(PaymentAttemptId $id): ?PaymentAttempt
    {
        $row = $this->pdo
            ->query("SELECT * FROM payment_attempts WHERE id = '{$id->value}'")
            ->fetch();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByProviderReference(string $providerName, string $reference): ?PaymentAttempt
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM payment_attempts WHERE provider_name = :pn AND provider_reference = :ref LIMIT 1"
        );
        $stmt->execute(['pn' => $providerName, 'ref' => $reference]);
        $row = $stmt->fetch();

        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @return PaymentAttempt[] */
    public function findByPaymentId(PaymentId $paymentId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payment_attempts WHERE payment_id = :pid ORDER BY created_at ASC");
        $stmt->execute(['pid' => $paymentId->value]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    private function hydrate(array $row): PaymentAttempt
    {
        $currency = new Currency($row['currency_code'], (int) $row['currency_precision']);
        $amount   = Money::ofMinor((int) $row['amount_minor'], $currency);
        $data     = json_decode($row['data'], true);
        $class    = $data['_class'] ?? throw new \RuntimeException('Missing _class in attempt data');
        $attemptData = $class::fromArray(array_diff_key($data, ['_class' => true]));

        $attempt = new CardPaymentAttempt(
            id:                PaymentAttemptId::fromUuid($row['id']),
            paymentId:         PaymentId::fromUuid($row['payment_id']),
            providerName:      $row['provider_name'],
            amount:            $amount,
            data:              $attemptData,
            status:            AttemptStatus::from($row['status']),
            providerStatus:    $row['provider_status'],
            providerReference: $row['provider_reference'],
            createdAt:         new \DateTimeImmutable($row['created_at']),
        );

        $attempt->setVersion((int) $row['version']);

        return $attempt;
    }
}
