<?php

declare(strict_types=1);

namespace App\Domain\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kanal platform tanımı — statik, kiracısız, seed ile dolar.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 014.
 *
 * capabilities alanı, koddaki instanceof Supports* kontrolünün veritabanı
 * yansımasıdır; panel hangi sekmeleri göstereceğini buradan okur ve
 * "if type === ..." bloklarına ihtiyaç kalmaz.
 *
 * @property string $code
 * @property string $kind
 * @property array<string, bool> $capabilities
 * @property array<string, mixed> $rate_limit_profile
 * @property bool $is_beta
 */
class ChannelType extends Model
{
    public $incrementing = false;

    /**
     * BETA KANALLAR (kullanıcı kararı 21 Eki 2026): gerçek satıcı hesabıyla
     * uçtan uca doğrulanmamış ama satışa AÇIK kanallar. Panelde ve sitede
     * "Beta" rozeti taşır; yeni bağlantıda yazma izinleri ADR-010 gereği
     * kapalı doğar, bağlantı ve ilk hata 34Devs'e bildirilir
     * (`BetaChannelNotifier`). Trendyol, Shopify ve Etsy gerçek hesapta
     * doğrulandığı için listede YOK. Kanal doğrulanınca buradan çıkarılır.
     */
    public const BETA_CODES = [
        'woocommerce', 'hepsiburada', 'n11', 'ikas', 'ticimax', 'pazarama', 'ciceksepeti', 'ebay', 'kaufland',
    ];

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'kind',
        'adapter_class',
        'capabilities',
        'rate_limit_profile',
        'supports_webhooks',
        'is_active',
        'is_beta',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'rate_limit_profile' => 'array',
            'supports_webhooks' => 'boolean',
            'is_active' => 'boolean',
            'is_beta' => 'boolean',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class, 'channel_type_code', 'code');
    }

    public function isMarketplace(): bool
    {
        return $this->kind === 'marketplace';
    }
}
