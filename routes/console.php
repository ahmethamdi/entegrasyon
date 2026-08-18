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
