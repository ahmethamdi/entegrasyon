# Kanal sertifikasyonu (S15)

Her kanal × 12 yetenek için tek bir durum: **yönetim → Kanal sertifikasyonu**
(`/admin/certification`). Satıcı yalnız sade "Bu kanalda çalışanlar" listesini
görür (Kanallarım); site ve çözüm sayfaları aynı kaynaktan okur.

## Durum nereden gelir

| Durum | Kaynak | Elle mi? |
|---|---|---|
| NOT_SUPPORTED | adaptör `DeclaresUnsupportedCapabilities` beyanı (gerekçeli) | adaptörde |
| NOT_IMPLEMENTED | yeteneğin arayüzü yok (`ChannelCapability::requires/anyOf`) | hayır |
| IMPLEMENTED | arayüz var, test taraması kanıt bulamadı | hayır |
| MOCK_TESTED | `resources/certification/test-coverage.json` (test taraması) | hayır |
| SANDBOX_VERIFIED / PRODUCTION_VERIFIED / FAILED | `CertificationEvidence::records()` — kim, ne zaman, kanıt | **evet, kanıtla** |

**MOCK_TESTED gerçek doğrulama değildir** (sahte HTTP). Ekranda ayrı renkte durur.

## Yapılacaklar

- **Adaptör testi ekledin/değiştirdin** → `php artisan certification:scan-tests`, dosyayı commit et.
  Unutursan `ChannelCertificationContractTest::the_test_coverage_ledger_is_fresh` kırılır.
- **Gerçek hesapta denedin** → `app/Domain/Channels/Certification/CertificationEvidence.php`
  `records()`'a satır ekle: kanal, yetenekler, durum, tarih (YYYY-AA-GG), kim, kanıt (ne
  görüldü, commit). Emin olmadığını yazma. Aynı hücrede en yeni tarih geçerlidir.
- **Bilinçli yazılmayan yetenek** → `notes()`'a gerekçe (durum değişmez).
- **Yeni kanal** → klasörü (`Adapters/{Ad}/{Ad}Adapter.php`) ve seeder satırı yeterli;
  matrise, taramaya ve site tutarlılık testine kendiliğinden girer.

## Test taraması kuralı (tutucu)

Bir test yöntemi bir hücreyi ancak (1) kanalın adaptörünü adıyla anıyorsa (ya da dosya
tek kanalın dosyasıysa) ve (2) yeteneğin adaptör yöntemini doğrudan çağırıyorsa
(`->pushInventory(` …, iptalde "cancel", hız sınırında "429" anahtar kelimesiyle) sayar.
İş (job) ya da yardımcı yöntem üzerinden dolaylı çağrılar sayılmaz: eksik sayım
"IMPLEMENTED" bırakır, fazla sayım yalan "test edildi" derdi.

## Hata izleme

Matris ekranındaki "Hata (24 sa)": kanala giden başarısız istek (`api_calls` 4xx/5xx/ağ)
/ ölen senkron işlem (`sync_operations.status = dead`), bütün kiracılar, kanal türüne göre.
