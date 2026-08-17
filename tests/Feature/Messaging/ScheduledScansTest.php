<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kurtarma taramaları GERÇEKTEN ZAMANLANMIŞ MI?
 *
 * Mimari Karar Dokümanı v2.2 · §6 · bütünlük taramaları, §15 · zamanlanmış işler.
 *
 * SINIFIN VAR OLMASI ONU KİMSENİN ÇAĞIRDIĞI ANLAMINA GELMEZ. Bu testin
 * varlık nedeni tam olarak bu: bütünlük taraması yazılıp zamanlayıcıya
 * bağlanmazsa hiç çalışmaz ve kurtardığı sanılan olaylar sessizce kaybolur.
 * Kod incelemesinde fark edilmez çünkü sınıf kusursuz görünür; yalnızca
 * üretimde bir Redis kaybının ardından kimse gelmediğinde anlaşılır.
 *
 * Frekanslar §15 tablosundan gelir:
 *   inbox:recover              1 dakika
 *   sync:detect-stuck          5 dakika   (seviye 2)
 *   outbox:detect-unconsumed  10 dakika   (seviye 1)
 *   reconcile:hot              5 dakika   (§10 · sıcak katman)
 *
 * outbox:relay BU LİSTEDE YOKTUR ve olmamalıdır: o bir kuyruk işi veya
 * zamanlanmış komut değil, supervisor altında sürekli çalışan bir süreçtir.
 * Zamanlayıcıya bağlanırsa dakikada bir yeni sonsuz döngü başlatılır.
 */
final class ScheduledScansTest extends TestCase
{
    /**
     * Üç kurtarma taraması da zamanlayıcıda kayıtlıdır.
     */
    #[Test]
    public function recovery_scans_are_registered_in_the_scheduler(): void
    {
        $commands = $this->scheduledCommands();

        foreach ([
            'inbox:recover',
            'sync:detect-stuck',
            'outbox:detect-unconsumed',
            'reconcile:hot',
        ] as $command) {
            $this->assertContains(
                $command,
                array_keys($commands),
                "{$command} zamanlayıcıda kayıtlı değil — tarama hiç çalışmaz.",
            );
        }
    }

    /** Frekanslar §15 tablosuyla birebir aynıdır. */
    #[Test]
    public function scan_frequencies_match_the_document(): void
    {
        $commands = $this->scheduledCommands();

        // Seviye 1: her 10 dakikada.
        $this->assertSame('*/10 * * * *', $commands['outbox:detect-unconsumed']);

        // Seviye 2: her 5 dakikada. Daha sık, çünkü Redis iş kaybı outbox
        // kaybından daha yakın bir halkada olur ve stok kanala hiç gitmez.
        $this->assertSame('*/5 * * * *', $commands['sync:detect-stuck']);

        // §10 · sıcak katman da beş dakikalık: sürüklenme ne kadar geç fark
        // edilirse o kadar çok yanlış stokla satış yapılır.
        $this->assertSame('*/5 * * * *', $commands['reconcile:hot']);

        // Gelen hat kurtarması: dakikalık. Kaybedilen şey SİPARİŞTİR.
        $this->assertSame('* * * * *', $commands['inbox:recover']);
    }

    /**
     * Taramalar maintenance kuyruğunda ve üst üste binmeden çalışır.
     *
     * withoutOverlapping OLMADAN: yavaş bir tur bitmeden ikincisi başlar,
     * iki kopya aynı olayları yeniden yayına alır ve publish_attempts
     * sayacı iki kat hızlı tükenir — kritik alarm eşiği anlamsız kalır.
     * (Sorgudaki FOR UPDATE SKIP LOCKED bunu zaten büyük ölçüde önler;
     * bu ikinci savunma hattıdır ve boşa dönen tur maliyetini de keser.)
     */
    #[Test]
    public function scans_do_not_overlap(): void
    {
        foreach ($this->scheduledEvents() as $event) {
            if (! str_contains((string) $event->command, 'detect')) {
                continue;
            }

            $this->assertTrue(
                $event->withoutOverlapping,
                "{$event->command} üst üste binmeye açık — iki kopya aynı satırları işler.",
            );
        }
    }

    /**
     * ZAMANLANMIŞ OLMAK YETMEZ — KOMUT KAYITLI DA OLMALI.
     *
     * Domain klasörlerindeki komutlar otomatik keşfedilmez; Laravel yalnızca
     * app/Console/Commands altını tarar ve bu proje komutları bootstrap/app.php
     * içinde AÇIKÇA kaydeder. Kayıt atlanırsa zamanlayıcı komutu dakikası
     * geldiğinde çağırır, artisan "böyle bir komut yok" der ve tarama hiç
     * çalışmaz. schedule:list yine kusursuz görünür — bu yüzden ayrı iddia.
     */
    #[Test]
    public function scheduled_scan_commands_are_actually_registered(): void
    {
        $registered = array_keys($this->app[Kernel::class]->all());

        foreach ([
            'inbox:recover',
            'sync:detect-stuck',
            'outbox:detect-unconsumed',
            'reconcile:hot',
        ] as $command) {
            $this->assertContains(
                $command,
                $registered,
                "{$command} artisan'a kayıtlı değil — zamanlayıcı çağırınca komut bulunamaz.",
            );
        }
    }

    /** Komutlar gerçekten çalışır ve sıfırla çıkar. */
    #[Test]
    public function scan_commands_execute_successfully(): void
    {
        $this->artisan('outbox:detect-unconsumed')->assertSuccessful();
        $this->artisan('sync:detect-stuck')->assertSuccessful();
    }

    /**
     * SÜREKLİ ÇALIŞAN SÜREÇ ZAMANLAYICIYA BAĞLANMAZ.
     *
     * outbox:relay supervisor altında sonsuz döngü olarak yaşar. Zamanlanmış
     * olsaydı dakikada bir yeni bir sonsuz döngü daha başlatılır ve süreçler
     * birikirdi.
     */
    #[Test]
    public function the_continuous_relay_process_is_not_scheduled(): void
    {
        $this->assertArrayNotHasKey(
            'outbox:relay',
            $this->scheduledCommands(),
            'outbox:relay sürekli bir süreçtir; zamanlanırsa süreçler birikir.',
        );
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * Zamanlanmış artisan komutları: signature => cron ifadesi.
     *
     * @return array<string, string>
     */
    private function scheduledCommands(): array
    {
        $commands = [];

        foreach ($this->scheduledEvents() as $event) {
            $signature = $this->signatureOf((string) $event->command);

            if ($signature !== null) {
                $commands[$signature] = $event->expression;
            }
        }

        return $commands;
    }

    /** @return list<Event> */
    private function scheduledEvents(): array
    {
        return app(Schedule::class)->events();
    }

    /**
     * Zamanlayıcı komutu tam PHP yoluyla saklar; içinden artisan
     * signature'ını çıkarır.
     */
    private function signatureOf(string $command): ?string
    {
        // Örnek: '/usr/bin/php8.3' 'artisan' outbox:detect-unconsumed
        if (preg_match("/'artisan'\s+(\S+)/", $command, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], "'\"");
    }
}
