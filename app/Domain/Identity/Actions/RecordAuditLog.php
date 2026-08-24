<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Denetim kaydı yazar — MASKELEYEREK.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · "Denetim kaydı" ve
 * "Kişisel veri ve sır maskeleme".
 *
 * ─────────────────────────────────────────────────────────────────────
 * `changes` MASKELİDİR — ŞEMA DEĞİL BU SINIF GARANTİ EDER
 * ─────────────────────────────────────────────────────────────────────
 * §4 kolonu "changes (JSONB, maskeli)" olarak tanımlar ama maskelemeyi
 * bir kolon tipi zorlayamaz. Yazan TEK yol burasıdır ve her yük
 * `PayloadRedactor`'ün katman 1'inden geçer.
 *
 * Bedeli açıktır: kimlik bilgisi güncellemesi denetlenirken sırrın
 * kendisi bu tabloya düşerse kasa şifrelemesinin tüm anlamı kaybolur —
 * sır şifresiz bir jsonb kolonunda düz metin durur, veritabanı yedeğine
 * girer ve denetim ekranı onu panele taşır.
 *
 * KATMAN 2 BURADA ÇALIŞTIRILMAZ ve bu bilinçlidir: katman 2 "bilinen sır
 * DEĞERLERİNİ" arar ve o değerler bağlantının kasasındadır. Denetim
 * kaydının yükünü ÇAĞIRAN kurar ve oraya asla ham sır konmaz — konsaydı
 * asıl hata çağrı yerindedir, maskelemede değil. Katman 1 anahtar adına
 * bakar ve yanlışlıkla eklenmiş bir `consumer_secret` alanını yakalar;
 * bu, buradaki gerçek risktir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DENETİM YAZIMI ASIL İŞİ DÜŞÜRMEZ
 * ─────────────────────────────────────────────────────────────────────
 * `api_calls` günlüklemesiyle AYNI kural (§13 · faz 1.4): denetim yan
 * iştir. Dolu disk veya kilitli tablo yüzünden kanal bağlama veya stok
 * düzeltme BAŞARISIZ OLMAMALIDIR — kullanıcının işi denetim kaydından
 * önemlidir. Hata yutulur ve uygulama günlüğüne yazılır.
 *
 * BUNUN BEDELİ KABUL EDİLMİŞTİR: kayıt sessizce kaybolabilir. Alternatif,
 * denetimi zorunlu kılıp asıl işi düşürmektir ve o, denetimin korumaya
 * çalıştığı şeyden büyük zarar verir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÇAĞIRANIN TRANSACTION'INA KATILIR
 * ─────────────────────────────────────────────────────────────────────
 * Kendi transaction'ını AÇMAZ. `AdjustStock` ve `ConnectChannel` zaten
 * bir transaction içindedir ve denetim kaydı o işin parçasıdır: iş geri
 * alınırsa kaydı da geri alınmalıdır. Ayrı transaction açsaydı, geri
 * alınmış bir stok düzeltmesi denetim izinde OLMUŞ gibi görünürdü.
 */
final class RecordAuditLog
{
    public function __construct(
        private readonly PayloadRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  Maskelenecek yük.
     */
    public function run(
        AuditAction $action,
        string $subjectType,
        ?string $subjectId = null,
        array $changes = [],
        ?string $userId = null,
        ?string $tenantId = null,
    ): ?AuditLog {
        try {
            $redacted = $this->redactor->redact($changes);

            return AuditLog::query()->create([
                'tenant_id' => $tenantId ?? TenantContext::idOrFail(),
                'user_id' => $userId ?? $this->currentUserId(),
                'action' => $action->value,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'changes' => $changes === [] ? null : $redacted,
                'ip' => $this->currentIp(),
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Denetim yazımı asıl işi düşürmez — gerekçe sınıf başlığında.
            Log::warning('audit.write_failed', [
                'action' => $action->value,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Model üzerinden kısayol — `subject_type` sınıf adından türer. */
    public function forModel(
        AuditAction $action,
        Model $subject,
        array $changes = [],
        ?string $userId = null,
    ): ?AuditLog {
        return $this->run(
            action: $action,
            subjectType: $subject->getTable(),
            subjectId: (string) $subject->getKey(),
            changes: $changes,
            userId: $userId,
        );
    }

    /**
     * Oturumdaki kullanıcı — konsol ve kuyruk yolunda NULL.
     *
     * `auth()` bir istek dışında da çözülebilir ama kullanıcı taşımaz;
     * `null` dönmek doğru cevaptır ve kolon nullable olduğu için kayıt
     * yine yazılır (bkz. migration başlığı).
     */
    private function currentUserId(): ?string
    {
        try {
            $id = auth()->id();

            return $id === null ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }

    /** İstek dışında (zamanlanmış komut, kuyruk işi) IP YOKTUR. */
    private function currentIp(): ?string
    {
        try {
            if (! app()->bound(Request::class)) {
                return null;
            }

            return app(Request::class)->ip();
        } catch (Throwable) {
            return null;
        }
    }
}
