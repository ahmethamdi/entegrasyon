<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Yardım ekranı — §13 · Faz 4 · "Türkçe yardım dokümantasyonu".
 *
 * Doküman maddeyi SAYAR ama içeriğini tanımlamaz; içerik ve biçim
 * kararları `HelpController` içinde alınır ve gerekçeleri orada yazılıdır.
 *
 * DEĞİŞMEZ KURAL — EKRAN KİRACI VERİSİ OKUMAZ:
 *   Yardım metni tüm satıcılar için aynıdır ve hiçbir tenant-scoped sorgu
 *   yapmaz. Bu test onu KİRACI BAĞLAMI ALTINDA çağırarak doğrular: ekran
 *   yanlışlıkla kiracı verisi okumaya başlarsa bu dosya değil, izolasyon
 *   testleri kırılır — ama en azından ekranın çalıştığı garanti altındadır.
 *
 * DEĞİŞMEZ KURAL — BÖLÜM KİMLİKLERİ SÖZLEŞMEDİR:
 *   `id` alanları panelin başka ekranlarından `/help#stok` gibi doğrudan
 *   bağlantı verilebilsin diye sabittir. BEKLENEN METİNLE sınanır; yazan
 *   da okuyan da aynı diziyi kullandığı için bir yeniden adlandırma
 *   davranış testlerini yeşil bırakır ama dışarıdaki bağlantıları
 *   SESSİZCE kırardı. Bu projede aynı tuzak dört kez yaşandı
 *   (`MetricScope`, `AlertKey`, `plans.limits`, dil dosyaları).
 */
final class HelpScreenTest extends TestCase
{
    use RefreshDatabase;

    /** Yardım ekranı açılır ve bölümleri taşır. */
    #[Test]
    public function the_help_screen_renders_its_sections(): void
    {
        $sections = $this->sections($this->visit());

        $this->assertNotEmpty($sections, 'Yardım ekranı en az bir bölüm taşımalı.');

        foreach ($sections as $section) {
            $this->assertNotEmpty($section['title'], 'Her bölümün başlığı olmalı.');
            $this->assertNotEmpty($section['items'], 'Her bölüm en az bir soru taşımalı.');
        }
    }

    /**
     * BÖLÜM KİMLİKLERİ SABİTTİR — sözleşme testi.
     *
     * Beklenen METİNLE sınanır; `HelpController`'ı çağırıp sonucu
     * kendisiyle karşılaştırmak hiçbir şey korumazdı.
     */
    #[Test]
    public function the_section_anchors_are_stable(): void
    {
        $ids = array_column($this->sections($this->visit()), 'id');

        $this->assertSame(
            ['baslangic', 'stok', 'siparisler', 'senkron', 'kanallar', 'abonelik'],
            $ids,
            'Bölüm kimlikleri sabittir — değişirse `/help#stok` gibi bağlantılar SESSİZCE kırılır.',
        );
    }

    /**
     * HER SORUNUN BİR CEVABI VARDIR.
     *
     * Boş cevap ekranda soruyu tıklanabilir ama BOŞ gösterirdi — kullanıcı
     * cevabın yüklenmediğini sanardı.
     */
    #[Test]
    public function every_question_has_an_answer(): void
    {
        foreach ($this->sections($this->visit()) as $section) {
            foreach ($section['items'] as $item) {
                $this->assertNotEmpty($item['q'], 'Soru boş olamaz.');
                $this->assertNotEmpty($item['a'], "Cevap boş olamaz: {$item['q']}");
            }
        }
    }

    /**
     * SİSTEMİN GERÇEK TUZAKLARI ANLATILIR.
     *
     * Yardım ekranının varlık sebebi genel bir "nasıl kullanılır" metni
     * değil, satıcının PANELDE KARŞILAŞTIĞI ve açıklanmazsa destek
     * çağrısına dönüşen durumlardır (§17 · destek yükü). Bu test o
     * konuların ekrandan sessizce düşmediğini korur.
     */
    #[Test]
    public function the_content_covers_the_traps_that_generate_support_load(): void
    {
        $text = json_encode($this->sections($this->visit()), JSON_UNESCAPED_UNICODE);

        foreach (['fazla satış', 'eşleşme', 'kalıcı hata', 'kota'] as $topic) {
            $this->assertStringContainsString(
                $topic,
                mb_strtolower((string) $text),
                "Yardım metni '{$topic}' konusunu anlatmalı — açıklanmazsa destek çağrısına dönüşür.",
            );
        }
    }

    /** Giriş yapmamış kullanıcı yardım ekranını göremez — panelin parçasıdır. */
    #[Test]
    public function the_help_screen_requires_authentication(): void
    {
        $this->get('/help')->assertRedirect('/login');
    }

    private function visit(): TestResponse
    {
        $user = User::factory()->create();

        (new CreateTenant)->run(name: 'Test Şirket', owner: $user);

        return $this->actingAs($user)->get('/help');
    }

    /** @return list<array{id: string, title: string, intro: string, items: list<array{q: string, a: string}>}> */
    private function sections(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props']['sections'];
    }
}
