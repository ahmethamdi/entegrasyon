<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Contracts\InvoiceProvider;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Invoicing\Providers\Parasut\ParasutClient;
use App\Domain\Invoicing\Providers\Parasut\ParasutInvoiceProvider;
use RuntimeException;

/**
 * Hesabın entegratörü — her çağrıda YENİ nesne (`AdapterRegistry` kuralı:
 * önbelleklenseydi bir kiracının token'ı başka kiracının işine sızabilirdi).
 */
class InvoiceProviders
{
    public function for(InvoiceAccount $account): InvoiceProvider
    {
        return match ($account->provider) {
            InvoiceAccount::PROVIDER_PARASUT => new ParasutInvoiceProvider(
                new ParasutClient($account),
                $account->setting(InvoiceAccount::SETTING_SERIES),
            ),
            default => throw new RuntimeException("Bilinmeyen fatura entegratörü: {$account->provider}"),
        };
    }
}
