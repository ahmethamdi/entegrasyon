<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Support\Logging\PayloadRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * İki katmanlı maskeleme — §11'in BEŞ vakası.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · "Kişisel veri ve sır maskeleme".
 * Doküman bu testin dosya yolunu ve vaka listesini AÇIKÇA yazar.
 *
 * BAĞIMSIZ TESTTİR ve bu, sınıfın kendi sözleşmesidir: §11 bileşeni
 * "ayrı, bağımsız test edilebilir" olarak tanımlar ve "HTTP istemcisinin
 * parçası değil" der. Yalnızca `ChannelHttpClient` üzerinden dolaylı
 * sınansaydı, istemci değiştiğinde maskeleme kuralları da sessizce
 * kayardı — üstelik dolaylı test iç içe yapıyı ve kısa sır eşiğini HİÇ
 * çalıştırmıyordu.
 *
 * `Illuminate` TestCase DEĞİL, saf PHPUnit: sınıf çerçeveye hiç
 * dokunmaz ve veritabanı kurmak testi yavaşlatmaktan başka bir şey
 * yapmazdı.
 */
final class PayloadRedactorTest extends TestCase
{
    private PayloadRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new PayloadRedactor;
    }

    /** VAKA 1 — anahtar bazlı: {"api_key": "x"} → {"api_key": "[redacted]"} */
    #[Test]
    public function it_redacts_by_key_name(): void
    {
        $result = $this->redactor->redact(['api_key' => 'gizli_deger']);

        $this->assertSame(['api_key' => PayloadRedactor::REDACTED], $result);
    }

    /** VAKA 2 — iç içe: {"data": {"access_token": "x"}} → maskelenir */
    #[Test]
    public function it_redacts_nested_structures(): void
    {
        $result = $this->redactor->redact([
            'data' => [
                'access_token' => 'gizli_token',
                'deep' => ['password' => 'gizli_parola'],
            ],
        ]);

        $this->assertSame([
            'data' => [
                'access_token' => PayloadRedactor::REDACTED,
                'deep' => ['password' => PayloadRedactor::REDACTED],
            ],
        ], $result);
    }

    /**
     * VAKA 3 — değer bazlı: "Invalid key: SECRET123456" + knownSecrets
     *
     * KATMAN 2'NİN VARLIK SEBEBİ. Katman 1 anahtar ADINA bakar; buradaki
     * sır bir anahtarın değeri değil, DÜZ METNİN İÇİNDE geçen bir dizedir
     * ve katman 1 onu göremez. §11'in verdiği örnek birebir budur.
     */
    #[Test]
    public function it_redacts_known_secret_values_inside_free_text(): void
    {
        $result = $this->redactor->redact(
            'Invalid key: SECRET123456',
            ['SECRET123456'],
        );

        $this->assertSame('Invalid key: '.PayloadRedactor::REDACTED, $result);
    }

    /** Katman 2 dizinin İÇİNDEKİ serbest metinde de çalışır. */
    #[Test]
    public function layer_two_also_reaches_values_inside_arrays(): void
    {
        $result = $this->redactor->redact(
            ['message' => 'Auth failed for SECRET123456'],
            ['SECRET123456'],
        );

        $this->assertSame(
            ['message' => 'Auth failed for '.PayloadRedactor::REDACTED],
            $result,
        );
    }

    /**
     * VAKA 4 — kısa sır (< 8 karakter) YANLIŞ EŞLEŞME YAPMAZ.
     *
     * Sekiz karakterin altındaki bir "sır" metnin her yerinde geçer:
     * `consumer_key` değeri `cs` olsaydı "closed", "success" ve "css"
     * içindeki her `cs` maskelenir ve hata metni OKUNMAZ hale gelirdi —
     * yani teşhis imkânsızlaşır.
     *
     * MUTASYON UYARISI: bu testin gerçekten koruduğu şey EŞİĞİN
     * KENDİSİDİR. Eşik düşürülür veya kaldırılırsa test kırmızıya döner.
     */
    #[Test]
    public function short_secrets_are_ignored(): void
    {
        $result = $this->redactor->redact(
            'Store is temporarily closed',
            ['cs', 'ck', 'abc'],
        );

        $this->assertSame('Store is temporarily closed', $result);
    }

    /** Tam sekiz karakter SINIRDADIR ve maskelenir (eşik `>=`). */
    #[Test]
    public function a_secret_of_exactly_the_minimum_length_is_redacted(): void
    {
        $result = $this->redactor->redact('key is abcd1234', ['abcd1234']);

        $this->assertSame('key is '.PayloadRedactor::REDACTED, $result);
    }

    /** Yedi karakter maskelenmez — sınırın diğer yanı. */
    #[Test]
    public function a_secret_one_character_below_the_minimum_is_ignored(): void
    {
        $result = $this->redactor->redact('key is abcd123', ['abcd123']);

        $this->assertSame('key is abcd123', $result);
    }

    /**
     * VAKA 5 — YAPI KORUNUR: anahtarlar silinmez, yalnızca değerler değişir.
     *
     * Maskeleme bir SİLME değil DEĞİŞTİRME işlemidir. Anahtar silinseydi
     * denetim ve hata ayıklama için gereken "hangi alan vardı" bilgisi de
     * kaybolurdu — `api_calls` satırına bakan kişi isteğin biçimini hiç
     * göremezdi.
     */
    #[Test]
    public function the_structure_is_preserved(): void
    {
        $result = $this->redactor->redact([
            'sku' => 'TSH-001',
            'api_key' => 'gizli',
            'quantity' => 5,
            'active' => true,
            'note' => null,
        ]);

        $this->assertSame(
            ['sku', 'api_key', 'quantity', 'active', 'note'],
            array_keys((array) $result),
        );

        $this->assertSame('TSH-001', $result['sku']);
    }

    /**
     * KATMAN 2'NİN JSON GİDİŞ-DÖNÜŞÜ TİPLERİ BOZMAZ.
     *
     * Katman 2 diziyi `json_encode` edip ara-değiştir yapar ve geri
     * çözer. Bu gidiş-dönüş sayıları dizeye çevirseydi `quantity` alanı
     * `5` yerine `"5"` olur ve okuyan taraf sessizce farklı bir tip
     * görürdü.
     */
    #[Test]
    public function layer_two_preserves_scalar_types(): void
    {
        $result = $this->redactor->redact(
            ['quantity' => 5, 'active' => true, 'note' => null, 'price' => 1.5],
            ['bilinen_sir_degeri'],
        );

        $this->assertSame(5, $result['quantity']);
        $this->assertTrue($result['active']);
        $this->assertNull($result['note']);
        $this->assertSame(1.5, $result['price']);
    }

    /** Anahtar eşleşmesi BÜYÜK/KÜÇÜK HARFTEN bağımsızdır. */
    #[Test]
    public function key_matching_is_case_insensitive(): void
    {
        $result = $this->redactor->redact(['Authorization' => 'Basic abc']);

        $this->assertSame(['Authorization' => PayloadRedactor::REDACTED], $result);
    }

    /**
     * KİŞİSEL VERİ DE MASKELENİR — §11 sırla aynı listede sayar.
     *
     * Sipariş yükü alıcı adı, e-posta, telefon ve adres taşır; bunlar
     * `api_calls` satırına düşer ve o tablo aylarca saklanır (4xx/5xx için
     * 90 gün).
     */
    #[Test]
    public function personal_data_keys_are_redacted(): void
    {
        $result = $this->redactor->redact([
            'email' => 'musteri@example.com',
            'phone' => '+90 555 000 0000',
            'address1' => 'Bir Sokak No 1',
            'tc_kimlik' => '11111111111',
        ]);

        foreach ($result as $value) {
            $this->assertSame(PayloadRedactor::REDACTED, $value);
        }
    }

    /** Bilinen sır verilmezse katman 2 çalışmaz ve metin değişmez. */
    #[Test]
    public function without_known_secrets_free_text_is_untouched(): void
    {
        $this->assertSame(
            'Invalid key: SECRET123456',
            $this->redactor->redact('Invalid key: SECRET123456'),
        );
    }
}
