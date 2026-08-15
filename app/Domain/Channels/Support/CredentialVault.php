<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use Illuminate\Support\Facades\Crypt;
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
            $this->store($connection, $secrets, $credential->scope, $credential->expires_at);
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
