<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kanal kimlik bilgisi kasası — Laravel Crypt üzerine ince sarmalayıcı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · Kimlik bilgisi yönetimi.
 *
 * ÖZEL KRİPTO YAZILMAZ. Anahtar rotasyonu Laravel'in APP_PREVIOUS_KEYS
 * mekanizmasıyla yürür: Crypt::decryptString() önce güncel anahtarı, sonra
 * sırayla eski anahtarları dener. key_version kolonu yalnızca hangi kayıtların
 * henüz yeniden şifrelenmediğini görmek için tutulur — çözme yönlendirmesi
 * için KULLANILMAZ.
 *
 * Kimlik bilgileri hiçbir koşulda loglanmaz.
 */
final class CredentialVault
{
    /**
     * Kimlik bilgilerini şifreleyip saklar.
     *
     * Mevcut aktif kayıt varsa üzerine yazar; iptal edilmişler korunur.
     *
     * @param  array<string, mixed>  $secrets
     */
    public function store(
        ChannelConnection $connection,
        array $secrets,
        ?string $scope = null,
        ?\DateTimeInterface $expiresAt = null,
    ): ChannelCredential {
        $credential = $this->write($connection, $secrets, $scope, $expiresAt);

        // ⚠️ YENİ KİMLİK = DEVRE KAPANIR (§12).
        //
        // AUTHENTICATION devreyi SÜRESİZ açar ve "kullanıcı kimlik
        // bilgisini yenileyince reset() çağrılır" kuralı vardı — ama hiçbir
        // yer çağırmıyordu. Tek bir 401 (ör. Etsy token'ı yenileme
        // turundan önce doldu) bağlantıyı sonsuza kadar durdururdu: token
        // yenilense de, satıcı OAuth'u baştan yapsa da push işleri her beş
        // dakikada ertelenirdi.
        //
        // Kimliğin girdiği TEK kapı burası (bağlama formu, OAuth geri
        // dönüşü, token yenileme) — reset'i çağıranlara dağıtmak birinin
        // unutulması demekti.
        //
        // COMMIT'TEN SONRA: yazım geri alınırsa devre eski kimlikle açık
        // kalmalı. Transaction yoksa hemen çalışır.
        DB::afterCommit(fn () => app(CircuitBreaker::class)->reset($connection->id));

        return $credential;
    }

    /**
     * Şifreli yazımın kendisi — devreye DOKUNMAZ.
     *
     * Anahtar rotasyonu (`read()`) da buradan yazar: AYNI kimliği yeni
     * anahtarla yeniden şifrelemek kimlik değişikliği DEĞİLDİR ve açık
     * devreyi kapatmamalıdır.
     *
     * @param  array<string, mixed>  $secrets
     */
    private function write(
        ChannelConnection $connection,
        array $secrets,
        ?string $scope,
        ?\DateTimeInterface $expiresAt,
    ): ChannelCredential {
        $payload = json_encode($secrets, JSON_THROW_ON_ERROR);

        /** @var ChannelCredential|null $existing */
        $existing = $connection->activeCredential()->first();

        $attributes = [
            'encrypted_payload' => Crypt::encryptString($payload),
            'key_version' => $this->currentKeyVersion(),
            'scope' => $scope,
            'expires_at' => $expiresAt,
            'refreshed_at' => now(),
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return ChannelCredential::create([
            'tenant_id' => $connection->tenant_id,
            'channel_connection_id' => $connection->id,
            ...$attributes,
        ]);
    }

    /**
     * Kimlik bilgilerini çözer.
     *
     * Kayıt eski bir anahtar sürümüyle şifrelenmişse fırsatçı olarak yeniden
     * şifrelenir; böylece rotasyon arka planda kendiliğinden tamamlanır.
     *
     * @return array<string, mixed>
     */
    public function read(ChannelConnection $connection): array
    {
        /** @var ChannelCredential|null $credential */
        $credential = $connection->activeCredential()->first();

        if ($credential === null) {
            throw new RuntimeException(
                "Bağlantı için aktif kimlik bilgisi yok: {$connection->id}"
            );
        }

        // APP_PREVIOUS_KEYS otomatik denenir; elle anahtar seçimi yapılmaz.
        $decoded = json_decode(
            Crypt::decryptString($credential->encrypted_payload),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        /** @var array<string, mixed> $secrets */
        $secrets = is_array($decoded) ? $decoded : [];

        if ($credential->key_version !== $this->currentKeyVersion()) {
            $this->write($connection, $secrets, $credential->scope, $credential->expires_at);
        }

        return $secrets;
    }

    /**
     * Maskeleme için sır değerleri.
     *
     * PayloadRedactor bu listeyi kullanarak, kimlik bilgisi bir hata mesajının
     * içinde düz metin geçse bile maskeler (§11 · katman 2).
     *
     * @return list<string>
     */
    public function secretValues(ChannelConnection $connection): array
    {
        $values = [];

        foreach ($this->read($connection) as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function revoke(ChannelConnection $connection): void
    {
        $connection->activeCredential()->first()?->forceFill([
            'revoked_at' => now(),
        ])->save();
    }

    private function currentKeyVersion(): int
    {
        return (int) config('entegrasyon.credentials.key_version', 1);
    }
}
