<?php

declare(strict_types=1);

namespace App\Provider;

use Payroad\Port\Provider\ProviderFactoryInterface;

final class AsyncMockCardProviderFactory implements ProviderFactoryInterface
{
    public function create(array $config): AsyncMockCardProvider
    {
        return new AsyncMockCardProvider(
            providerName: $config['provider_name'] ?? 'mock_card_async',
        );
    }
}
