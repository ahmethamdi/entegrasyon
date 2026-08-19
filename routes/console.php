<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Kurtarma ve bütünlük taramaları
|--------------------------------------------------------------------------
|
| Mimari Karar Dokümanı v2.2 · §6 · iki bütünlük taraması, §15 · zamanlanmış
| işler. Frekanslar §15 tablosundan birebir alınmıştır.
|
| BU BLOK OLMADAN TARAMALARIN HİÇBİR DEĞERİ YOKTUR. Sınıfın var olması onu
| kimsenin çağırdığı anlamına gelmez; zamanlanmayan bir kurtarma taraması
| yalnızca kurtardığı yanılsamasını üretir. ScheduledScansTest bu blokun
| varlığını ve frekanslarını doğrular.
|
| outbox:relay BURADA YOKTUR ve olmamalıdır: o supervisor altında sürekli
| çalışan bir süreçtir, zamanlanmış bir komut değil. Zamanlanırsa dakikada
| bir yeni sonsuz döngü başlar ve süreçler birikir.
|
| Hepsi maintenance kuyruğuna aittir (§15) ve üst üste binmez: yavaş bir tur
| bitmeden ikincisi başlarsa iki kopya aynı satırları işler.
*/

// Gelen hat kurtarması — KAYBEDİLEN ŞEY SİPARİŞTİR, bu yüzden dakikalık.
// Kayıt ile kuyruğa atma arasında süreç ölürse mesaj pending kalır ve
// webhook 202 döndüğü için kanal yeniden göndermez.
Schedule::command('inbox:recover')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// SEVİYE 2 — Redis işi kaybetti: operasyon var, kuyrukta karşılığı yok.
// Seviye 1'den daha sık, çünkü kayıp daha yakın bir halkada: fan-out zaten
// tamamlanmıştır ve tek eksik stoğun kanala gitmesidir.
Schedule::command('sync:detect-stuck')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// SEVİYE 1 — tüketici hiç çalışmadı: olay yayınlandı ama fan-out olmadı.
// Bu taramanın görmediğini hiçbir mekanizma görmez; seviye 2 de göremez,
// çünkü bulacağı operasyon hiç yaratılmamıştır.
Schedule::command('outbox:detect-unconsumed')
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// §10 · SICAK KATMAN MUTABAKATI — sürüklenme tespiti.
//
// Bütünlük taramalarından FARKLI bir soruyu cevaplar: onlar "iş kayboldu mu"
// diye sorar, bu "gönderdiğimizi sandığımız değer kanalda gerçekten var mı"
// diye sorar. Kanalda elle yapılan değişiklik, kanalın kendi satışı veya
// başarılı görünüp uygulanmamış bir yazma yalnızca BURADA yakalanır.
//
// Kapsam sıcak katman: son 30 dk satış olan, geçici hata almış, bir saattir
// bekleyen listing'ler — bağlantı başına en fazla 50 (§10 bütçe tablosu).
Schedule::command('reconcile:hot')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// §10 · ILIK KATMAN — SAATLİK, geniş kapsam.
//
// Sıcak katmanın DAR penceresine sığmayanı toplar: son 24 saatte satılmış
// ama son 30 dakikada satmamış listing, ve 24 saattir bekleyen iş. Bütçe
// altı katı (300), çünkü kapsam da geniş.
//
// SICAK KATMANLA AYNI EŞİKLERİ KULLANSAYDI HİÇBİR ŞEY EKLEMEZDİ: 300'lük
// bütçesini sıcak turun her beş dakikada bir zaten baktığı satırlarla
// doldururdu.
Schedule::command('reconcile:warm')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// §10 · SOĞUK KATMAN — GÜNLÜK, örneklemeli uzun kuyruk.
//
// TETİKLEYİCİSİ OLMAYAN SÜRÜKLENMEYİ YAKALAYAN TEK KATMAN. Sıcak ve ılık
// katmanların dört sebep sorgusu bir OLAY arar (taze satış, geçici hata,
// bekleyen iş, sürüklenme geçmişi); satmayan ve hata almamış bir listing
// kanal panelinden elle değiştirildiğinde o dört sorgunun hiçbirine
// takılmaz. Örneklem `last_observed_at NULLS FIRST` sırasıyla ilerler, yani
// hiç bakılmamış satır BAŞA gelir ve her satıra sırayla gelinmesi garanti
// edilir.
//
// GÜNLÜK VE 05:00: bütçe zaten oransal (aktif listing'lerin %2'si) ve uzun
// kuyruk tanımı gereği yavaş değişir; saatlik koşmak aynı satırları
// gereksizce yeniden okurdu. 03:00 taksonomi, 04:00 api_calls saklama —
// üçü aynı bakım penceresinde üst üste binmiyor.
Schedule::command('reconcile:cold')
    ->dailyAt('05:00')
    ->onOneServer()
    ->withoutOverlapping();

// §13 · Faz 2 · TAKSONOMİ — kanal kategori ağacı.
//
// GÜNLÜK, SAATLİK DEĞİL: kategori ağacı sık değişmez ve 30 bin satırlık bir
// ağacı saatte bir okumak kanal kotasını boşuna harcar. Gece 03:00 seçildi:
// satış trafiği en düşük, kota en boş.
//
// Öznitelikler burada çekilmez (yaprak başına ayrı istek, 30 bin yaprakta
// tur saatler sürer); eşleştirme ekranı talebe bağlı çeker.
Schedule::command('taxonomy:sync')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping();

// §13 · Faz 2 · onay durumu takibi — SAATLİK.
//
// Trendyol'un onay süreci saatler sürer; dakikalık yoklama kotayı tüketir
// ve hiçbir şey kazandırmaz. Onaysız listing'e stok gönderilmediği için
// (fan-out `lifecycle_status = 'live'` filtresi) gecikme stok akışını
// bozmaz — yalnızca ürünün yayına giriş anını geciktirir.
Schedule::command('approval:track')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// §13 · Faz 2 · SİPARİŞ YOKLAMASI — webhook göndermeyen kanallar.
//
// BEŞ DAKİKA: Trendyol webhook göndermez ve sipariş yalnızca bu turla
// gelir. Dakikalık koşmak kotayı 5 katına çıkarırdı ve satıcı seviyesine
// göre değişen hız sınırı düşük seviyeli hesapları 429'a sokardı;
// 15 dakika ise stok düşüşünü demoda görünmeyecek kadar geciktirirdi.
// reconcile:hot ile aynı ritim — kota hesabı buna göre kurulmuş (§10).
//
// Kayıp sipariş riski bu frekansla BÜYÜMEZ: pencere geriye bakar ve
// başarısız turda imleç ilerlemez, yani gecikme telafi edilir.
Schedule::command('orders:poll')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// §13 · Faz 3 · api_calls SAKLAMA — GÜNLÜK, gece 04:00.
//
// api_calls en çok yazılan tablodur ve `expires_at` ilk günden beri
// doldurulur (2xx +7 gün, 4xx/5xx +90 gün) — ama silen bir şey olmadan o
// alan yalnızca bir NİYETTİR ve tablo sınırsız büyür.
//
// GÜNLÜK YETER: saklama süreleri gün ölçeğindedir, saatlik koşmak aynı işi
// 24 kez yapıp hiçbir şey kazandırmaz. 04:00 seçildi — taksonomi turu
// 03:00'te bitiyor ve ikisi aynı bakım penceresinde üst üste binmiyor;
// satış trafiği de en düşük.
//
// Tur başına üst sınır var (§ PruneApiCalls): birikim tek turda erimezse
// kalanı yarın gider. withoutOverlapping bu yüzden zorunlu — sınıra dayanan
// bir tur uzun sürer ve ikinci kopya aynı satırları seçmeye çalışırdı.
Schedule::command('api-calls:prune')
    ->dailyAt('04:00')
    ->onOneServer()
    ->withoutOverlapping();

// §11 · METRİK ANLIK GÖRÜNTÜLERİ — saatlik (§15 tablosu birebir).
//
// SAATLİK YETER VE GEREKİR. Daha sık koşmak on üç ağır toplama sorgusunu
// (`percentile_cont` tam tarama ister) gereksiz sıklıkta çalıştırır; daha
// seyrek koşmak grafiği okunamaz kılar — günde bir nokta ile "gecikme
// öğleden sonra tırmanıyor" görülemez.
//
// BU BLOK OLMADAN TOPLAMA HİÇ ÇALIŞMAZ ve panel boş bir grafik gösterir:
// sınıfın var olması onu kimsenin çağırdığı anlamına gelmez. §17 bu maddeyi
// P0'a koyuyor ("ölçülmeyen güvenilirlik iddia edilemez") — zamanlanmamış
// bir metrik toplayıcı yalnızca ölçtüğü yanılsamasını üretir.
Schedule::command('metrics:capture')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();
