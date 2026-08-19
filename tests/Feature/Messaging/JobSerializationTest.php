<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Catalog\Jobs\ImportProductsJob;
use App\Domain\Messaging\Jobs\ConsumeOutboxEvent;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Jobs\PushListing;
use App\Domain\Sync\Jobs\PushPrices;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KUYRUĞA GİREN HER İŞ SERİLEŞTİRİLİP GERİ OKUNABİLMELİDİR.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · kuyruk ve kiracı bağlamı.
 *
 * Gerçek worker işi Redis'e YAZAR ve oradan GERİ OKUR. Testler işi doğrudan
 * kurup `handle()` çağırdığı için bu gidiş-dönüş hiç yaşanmaz; serileştirmede
 * patlayan bir iş tüm test paketi yeşilken üretimde HİÇ çalışmaz.
 *
 * SOMUT TUZAK — PROMOTED READONLY MİRAS:
 *   `SerializesModels::__unserialize()` özellikleri ALT SINIFIN kapsamından
 *   yeniden atar. PHP, ana sınıfta tanımlanmış bir `readonly` özelliğin alt
 *   sınıf kapsamından ilklenmesine izin VERMEZ ve deserialize
 *   "Cannot initialize readonly property ... from scope ..." ile düşer.
 *
 *   Bu hata tam olarak böyle bulundu: `ConsumeOutboxEvent` kuyruktan geri
 *   okunamıyordu ve gerçek worker'da HER outbox olayı düşüyordu — 340 test
 *   yeşilken.
 */
final class JobSerializationTest extends TestCase
{
    /**
     * Kuyruğa giren işler ve temsilî yükleri.
     *
     * @return array<string, ShouldQueue>
     */
    public static function queuedJobs(): array
    {
        return [
            'ConsumeOutboxEvent' => new ConsumeOutboxEvent('tenant-id', 'event-id'),
            'ProcessInboxMessage' => new ProcessInboxMessage('tenant-id', 'message-id'),
            'PushInventory' => new PushInventory('operation-id', 'tenant-id'),
            'PushListing' => new PushListing('operation-id', 'tenant-id'),
            'PushPrices' => new PushPrices('operation-id', 'tenant-id'),
            'ImportProductsJob' => new ImportProductsJob('tenant-id', 'import-id'),
        ];
    }

    /**
     * Her iş kuyruk gidiş-dönüşünden sağ çıkmalı ve yükünü korumalı.
     */
    #[Test]
    public function every_queued_job_survives_a_serialization_round_trip(): void
    {
        foreach (self::queuedJobs() as $name => $job) {
            $restored = unserialize(serialize($job));

            $this->assertInstanceOf(
                $job::class,
                $restored,
                "{$name} kuyruktan geri okunamıyor.",
            );

            // Yük KORUNMALI: kimlikler kaybolursa iş yanlış satırı işler.
            foreach (get_object_vars($job) as $property => $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $this->assertSame(
                    $value,
                    $restored->{$property},
                    "{$name}::\${$property} gidiş-dönüşte korunmalı.",
                );
            }
        }
    }

    /**
     * Kiracı kimliği TAŞIYAN her iş onu gidiş-dönüşte korumalı.
     *
     * Kimlik kaybolursa iş bağlamı kuramaz ve ilk tenant-scoped sorguda
     * düşer — ya da daha kötüsü, YANLIŞ kiracının bağlamıyla çalışır.
     */
    #[Test]
    public function tenant_id_survives_the_round_trip(): void
    {
        foreach (self::queuedJobs() as $name => $job) {
            $restored = unserialize(serialize($job));

            $this->assertSame(
                'tenant-id',
                $restored->tenantId,
                "{$name} kiracı kimliğini gidiş-dönüşte kaybediyor.",
            );
        }
    }
}
