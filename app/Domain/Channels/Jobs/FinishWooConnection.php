<?php

declare(strict_types=1);

namespace App\Domain\Channels\Jobs;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Actions\RegisterChannelWebhooks;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantAwareJob;

/**
 * Woo anahtarı callback'le geldikten SONRA: sağlık kontrolü + webhook kaydı.
 *
 * Callback isteğinin içinde YAPILMAZ: Woo callback'in cevabını satıcının
 * tarayıcısını bekleterek bekler (60 sn) ve 200 dışı her sonuçta anahtarı
 * siler. Mağazaya geri istek atmak callback'i yavaşlatır ve yavaş mağazada
 * anahtarın kendisini kaybettirirdi.
 *
 * Yazma izinleri (ürün/fiyat/stok) bu işte AÇILMAZ — ADR-010: kimlik
 * doğrulandı ≠ yazmaya yetkili. Webhook kaydı bir YAPILANDIRMA yazmasıdır
 * (siparişleri OKUMAK için gerekir), katalog/stok/fiyat yazması değildir.
 */
final class FinishWooConnection extends TenantAwareJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(string $tenantId, public readonly string $connectionId)
    {
        parent::__construct($tenantId);

        $this->onQueue('listing:default');
    }

    protected function handleForTenant(): void
    {
        $connection = ChannelConnection::query()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $connection = app(CheckChannelHealth::class)->run($connection);

        if ($connection->status === 'active') {
            app(RegisterChannelWebhooks::class)->run($connection);
        }
    }
}
