<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\ErrorClass;

/**
 * Toplu işte reddedilen tek satırın sebebi ve sınıfı.
 *
 * Sınıfı ADAPTER verir (kanal gövdesini yalnız o anlar); varsayılan
 * `VALIDATION`dır: satır bazındaki ret neredeyse her zaman O SATIRA özgüdür
 * (geçersiz barkod, onaysız ürün, fiyat bandı) ve yeniden göndermek
 * DÜZELTMEZ — `SyncResultRecorder::recordSuccess`'in kısmi başarı
 * varsayımının aynısı. Kanal "teknik hata, yeniden gönder" diyorsa adapter
 * geçici bir sınıf seçer.
 */
final readonly class BatchItemFailure
{
    public function __construct(
        public string $reason,
        public ErrorClass $class = ErrorClass::VALIDATION,
    ) {}
}
