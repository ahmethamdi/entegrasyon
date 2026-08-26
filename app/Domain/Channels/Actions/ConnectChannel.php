<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Exceptions\AccountAlreadyConnectedException;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Channels\Support\StoreUrl;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Kanal bağlar: bağlantı satırı + kimlik bilgisi + sağlık kontrolü.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.4, §11 · kimlik bilgisi yönetimi.
 *
 * DEĞİŞMEZ KURAL — SAĞLIK KONTROLÜ GEÇMEDEN AKTİF OLMAZ:
 *   `status` yalnızca kanal gerçekten cevap verirse `active` olur. Yanlış
 *   anahtarla kurulan bağlantı `pending` kalır ve `last_error` taşır.
 *
 *   Gerekçe: aktif ama çalışmayan bağlantı en pahalı hata biçimidir.
 *   Kullanıcı ürün göndermeye başlar, her operasyon AUTHENTICATION ile
 *   kalıcı hataya düşer ve sorunun kaynağı ("anahtarı yanlış yazmışsın")
 *   ancak destek kaydıyla bulunur.
 *
 * DEĞİŞMEZ KURAL — SIRLAR YALNIZCA KASADA:
 *   `settings` şifrelenmemiş jsonb'dir ve panele olduğu gibi gönderilir;
 *   oraya anahtar yazılmaz. Şifreleme `CredentialVault`'un işidir.
 *
 * SAĞLIK KONTROLÜ TRANSACTION DIŞINDA:
 *   Ağ çağrısı bir transaction'ı saniyelerce açık tutardı. Satırlar önce
 *   commit edilir, kontrol sonra çalışır, sonuç ikinci bir yazımla işlenir.
 *   Arada süreç ölürse bağlantı `pending` kalır — güvenli taraf.
 *
 * KİMLİK BİLGİSİ SAĞLIKSIZ DURUMDA DA SAKLANIR: kullanıcı mağazası geçici
 * kapalıyken denemiş olabilir ve anahtarı yeniden girmeye zorlanmamalı.
 * Bağlantı `pending` olduğu için senkron zaten çalışmaz.
 */
final class ConnectChannel
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly CheckChannelHealth $checkHealth,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $secrets  Kasaya yazılır (ŞİFRELİ)
     * @param  array<string, mixed>  $settings  Kimlik alanları — `settings`
     *                                          kolonuna BİRLEŞTİRİLİR (ŞİFRESİZ)
     * @param  bool  $checkHealth  OAuth kanallarında `false`: kimlik henüz
     *                             GELMEDİ ve kontrol kimliksiz gider
     *
     * @throws AccountAlreadyConnectedException Mağaza başka kiracıya bağlıysa
     */
    public function run(
        string $channelTypeCode,
        string $label,
        string $storeUrl,
        array $secrets,
        array $settings = [],
        bool $checkHealth = true,
    ): ChannelConnection {
        $url = StoreUrl::parse($storeUrl);

        $this->guardAgainstForeignTenant($channelTypeCode, $url->host);

        $connection = DB::transaction(function () use (
            $channelTypeCode,
            $label,
            $url,
            $secrets,
            $settings,
        ): ChannelConnection {
            // Aynı kiracı aynı mağazayı yeniden bağlarsa YENİ SATIR AÇILMAZ:
            // anahtar yenileme akışı budur. Yeni satır açılsaydı
            // (tenant, type, account) kısıtı ihlal edilir ve listing'ler eski
            // bağlantıya asılı kalırdı.
            $connection = ChannelConnection::query()->firstOrNew([
                'channel_type_code' => $channelTypeCode,
                'external_account_id' => $url->host,
            ]);

            // Denetim olayı YAZMADAN ÖNCE belirlenir: `save()` sonrası
            // `exists` her koşulda true olur ve iki olay ayırt edilemezdi.
            $isNew = ! $connection->exists;

            $connection->fill([
                'tenant_id' => TenantContext::idOrFail(),
                'label' => $label,
                // ⚠️ MEVCUT AYARLAR KORUNUR, EZİLMEZ (`PushListing::
                // adoptRemoteIdentity` kuralının aynısı). Ezseydi
                // Shopify'ı yeniden bağlayan satıcı `location_gid`'ini,
                // Etsy'yi yeniden bağlayan `shop_id`'sini kaybeder ve
                // bağlantı bir daha ASLA sağlıklı olmazdı — üstelik
                // sebebi görünmeden.
                //
                // Kimlik alanları taban adresten SONRA yazılır: kanal
                // başına tanımlı bir alan `base_url` adını taşısaydı
                // kanalın kendi gerçeği kazanmalıdır.
                'settings' => [
                    ...$connection->settings ?? [],
                    'base_url' => $url->baseUrl,
                    ...$settings,
                ],
            ]);

            // Sağlık kontrolü henüz çalışmadı: durum beklemede.
            if (! $connection->exists) {
                $connection->status = 'pending';
                $connection->health_status = 'unknown';
            }

            $connection->save();

            // ⚠️ BOŞ `secrets` KASAYA YAZILMAZ — OAuth kanallarının hâli.
            //
            // Etsy formdan hiç anahtar İSTEMEZ: token'ları
            // `EtsyOAuthController::callback()` yazar. Boş bir kayıt
            // açılsaydı kasada hiçbir zaman geçerli olmayan ÖLÜ bir sır
            // dururdu ve `activeCredential()` onu bulup "kimlik var"
            // derdi — istek kimliksiz gider, kanal 401 döner ve
            // `AUTHENTICATION` KALICI sayılır (`97a7eb7` hata biçimi).
            if ($secrets !== []) {
                // Kimlik bilgisi kasaya yazılır — çağrıyı yapabilmek için zorunlu.
                $this->vault->store($connection, $secrets);
            }

            // DENETİM KAYDI (§11) — İKİ AYRI OLAY.
            //
            // "Kanal bağlantısı ekleme" ve "kimlik bilgisi güncelleme"
            // §11'in listesinde AYRI maddelerdir ve ayrı olmaları
            // gerekir: anahtar yenileme bir güven olayıdır ve "bu
            // mağazaya kim, ne zaman yeni anahtar verdi" sorusu
            // bağlantının ne zaman kurulduğundan bağımsız sorulur. Tek
            // olayda birleştirilselerdi ikinci soru cevapsız kalırdı.
            //
            // YÜKE SIR KONMAZ — yalnızca hangi anahtarların verildiği
            // (ADLARI) yazılır. Değerleri yazmak kasanın tüm anlamını
            // yok ederdi; `RecordAuditLog` maskelemesi ikinci savunmadır,
            // birincisi burada sırrı hiç göndermemektir.
            $this->audit->run(
                action: $isNew
                    ? AuditAction::CHANNEL_CONNECTED
                    : AuditAction::CHANNEL_CREDENTIAL_UPDATED,
                subjectType: 'channel_connections',
                subjectId: $connection->id,
                changes: [
                    'channel_type_code' => $channelTypeCode,
                    'external_account_id' => $url->host,
                    'label' => $label,
                    'secret_keys' => array_keys($secrets),
                ],
            );

            return $connection;
        });

        // ⚠️ OAUTH KANALINDA SAĞLIK KONTROLÜ ÇALIŞTIRILMAZ — HENÜZ.
        //
        // Kimlik bilgisi bu noktada YOKTUR: satıcı daha kanalın
        // yetkilendirme ekranına bile gitmedi. Koşsaydı istek kimliksiz
        // gider, kanal 401 döner ve `last_error` "anahtarın yanlış"
        // derdi — satıcı henüz hiçbir anahtar VERMEMİŞKEN bağlantısını
        // bozuk sanardı. Kontrolü callback yapar (`EtsyOAuthController`),
        // token kasaya yazıldıktan HEMEN sonra.
        if (! $checkHealth) {
            return $connection;
        }

        // Sağlık kontrolü commit'ten SONRA: ağ çağrısı transaction tutmaz.
        return $this->checkHealth->run($connection);
    }

    /**
     * Mağaza başka bir kiracıya bağlı mı?
     *
     * Son söz veritabanı kısıtındadır (`channel_connections_account_unique`);
     * bu kontrol yarışı kapatmaz, kullanıcıya anlaşılır hata verir.
     */
    private function guardAgainstForeignTenant(string $channelTypeCode, string $accountId): void
    {
        $ownerTenantId = TenantContext::runAsSystem(
            fn (): ?string => ChannelConnection::query()
                ->where('channel_type_code', $channelTypeCode)
                ->where('external_account_id', $accountId)
                ->value('tenant_id'),
        );

        if ($ownerTenantId !== null && $ownerTenantId !== TenantContext::idOrFail()) {
            throw AccountAlreadyConnectedException::for($channelTypeCode, $accountId);
        }
    }
}
