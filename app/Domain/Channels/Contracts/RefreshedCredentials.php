<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use DateTimeImmutable;

/**
 * Yenilenmiş kimlik bilgisi — adapter'ın döndürdüğü SONUÇ.
 *
 * V3.0 · §03 · Delta 3 · §20.
 *
 * ADAPTER BUNU VAULT'A YAZMAZ, DÖNER. v2.2'nin "adapter yan etkisizdir"
 * kuralı: girdi alır, kanalla konuşur, sonuç nesnesi döner. Yazmayı
 * ÇEKİRDEK yapar (`TokenRefresher` → `CredentialVault::store()`).
 *
 * Kural gevşetilseydi adapter kimlik bilgisi yazan ikinci bir yol açardı ve
 * `channel_credentials`'ın tek yazma kapısı olan kasa devre dışı kalırdı;
 * anahtar sürümü ve maskeleme yüzeyi ikiye bölünürdü.
 */
final readonly class RefreshedCredentials
{
    /**
     * @param  array<string, mixed>  $secrets  Kasaya yazılacak TAM sır kümesi
     * @param  DateTimeImmutable|null  $expiresAt  Yeni access token'ın ölüm anı
     * @param  string|null  $scope  Kanal scope'u değiştirdiyse yeni değer
     */
    public function __construct(
        public array $secrets,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $scope = null,
    ) {}
}
